<?php

declare(strict_types=1);

/*
 * This file is part of Flight Routing.
 *
 * PHP version 7.1 and above required
 *
 * @author    Divine Niiquaye Ibok <divineibok@gmail.com>
 * @copyright 2019 Biurad Group (https://biurad.com/)
 * @license   https://opensource.org/licenses/BSD-3-Clause License
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Flight\Routing\Matchers;

use Flight\Routing\CompiledRoute;
use Flight\Routing\Interfaces\RouteCompilerInterface;
use Flight\Routing\Route;

/**
 * The routes dumper for any kind of route compiler.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class SimpleRouteDumper
{
    /** @var array<string,mixed> */
    public $staticRoutes = [];

    /** @var mixed[] */
    public $regexpList = [];

    /** @var array<string,mixed> */
    public $urlsList = [];

    /** @var Route[] */
    public $routeList = [];

    /** @var string */
    private $cacheFile;

    public function __construct(string $cacheFile)
    {
        $this->cacheFile = $cacheFile;
    }

    /**
     * Dumps a set of routes to a string representation of executable code
     * that can then be used to match a request against these routes.
     *
     * @param \Traversable<int,Route> $collection
     */
    public function dump(\Traversable $collection, RouteCompilerInterface $compiler): void
    {
        // Warm up routes for export to $cacheFile.
        $this->warmCompiler($collection, $compiler);

        $generatedCode = <<<EOF
<?php

/**
 * This file has been auto-generated by the Flight Routing.
 */
return [
{$this->generateCompiledRoutes()}];

EOF;
        \file_put_contents($this->cacheFile, $generatedCode);

        if (\function_exists('opcache_invalidate') && \filter_var(\ini_get('opcache.enable'), \FILTER_VALIDATE_BOOLEAN)) {
            @\opcache_invalidate($this->cacheFile, true);
        }
    }

    protected static function exportRoute(Route $route): string
    {
        $properties = $route->get('all');
        $properties['methods'] = \array_keys($properties['methods']);
        $controller = $properties['controller'];
        $exported = '';

        if ($controller instanceof \Closure) {
            $closureRef = new \ReflectionFunction($controller);

            if ('{closure}' === $closureRef->name) {
                throw new \RuntimeException(\sprintf('Caching route handler as an anonymous function for "%s" is unspported.', $properties['name']));
            }

            $properties['controller'] = $closureRef->name;
        } elseif (\is_object($controller) || (\is_array($controller) && \is_object($controller[0]))) {
            $properties['controller'] = \sprintf('unserialize(\'%s\')', \serialize($controller));
        }

        foreach ($properties as $key => $value) {
            $exported .= \sprintf('        %s => ', self::export($key));
            $exported .= self::export($value);
            $exported .= ",\n";
        }

        return "[\n{$exported}    ]";
    }

    private static function indent(string $code, int $level = 1): string
    {
        return (string) \preg_replace('/^./m', \str_repeat('    ', $level) . '$0', $code);
    }

    /**
     * @internal
     *
     * @param mixed $value
     */
    private static function export($value, int $level = 2): string
    {
        if (null === $value) {
            return 'null';
        }

        if (!\is_array($value)) {
            if ($value instanceof Route) {
                return self::exportRoute($value);
            } elseif ($value instanceof CompiledRoute) {
                return "'" . \serialize($value) . "'";
            }

            return \str_replace("\n", '\'."\n".\'', \var_export($value, true));
        }

        if (!$value) {
            return '[]';
        }

        $i = 0;
        $export = '[';

        foreach ($value as $k => $v) {
            if ($i === $k) {
                ++$i;
            } else {
                $export .= self::export($k) . ' => ';

                if (\is_int($k) && $i < $k) {
                    $i = 1 + $k;
                }
            }

            if (\is_string($v) && 0 === \strpos($v, 'unserialize')) {
                $v = '\\' . $v . ', ';
            } else {
                $v = self::export($v) . ', ';
            }

            $export .= $v;
        }

        return \substr_replace($export, ']', -2);
    }

    /**
     * @param \Traversable<int,Route> $routes
     */
    private function warmCompiler(\Traversable $routes, RouteCompilerInterface $compiler): void
    {
        $regexpList = [];

        foreach ($routes as $index => $route) {
            $this->routeList[$index] = $route;

            // Reserved routes pattern to url ...
            $this->urlsList[$route->get('name')] = $compiler->compile($route, true);

            // Compile the route ...
            $compiledRoute = $compiler->compile($route);

            if (null !== $url = $compiledRoute->getStatic()) {
                $this->staticRoutes[$url] = [$index, $compiledRoute->getHostsRegex(), $compiledRoute->getVariables()];

                continue;
            }

            $regexpList[$compiledRoute->getRegex()] = [$index, $compiledRoute->getHostsRegex(), $compiledRoute->getVariables()];
        }

        $this->regexpList = $this->generateExpressions($regexpList);
    }

    /**
     * @param mixed[] $expressions
     * @param mixed[] $dynamicRoutes
     *
     * @return mixed[]
     */
    private function generateExpressions(array $expressions)
    {
        $variables = [];
        $tree = new ExpressionCollection();

        foreach ($expressions as $expression => $dynamicRoute) {
            [$pattern, $vars] = $this->filterExpression($expression);

            if (null === $pattern) {
                continue;
            }

            $dynamicRoute[] = $vars;
            \array_unshift($dynamicRoute, $pattern); // Prepend the $pattern ...

            $tree->addRoute($pattern, $dynamicRoute);
        }

        $code = '\'#^(?\'';
        $code .= $this->compileExpressionCollection($tree, 0, $variables);
        $code .= "\n    .')/?$#sD'";

        return [$code, $variables];
    }

    /**
     * Compiles a regexp tree of subpatterns that matches nested same-prefix routes.
     *
     * @param array<string,string> $vars
     */
    private function compileExpressionCollection(ExpressionCollection $tree, int $prefixLen, array &$vars): string
    {
        $code = '';
        $routes = $tree->getRoutes();

        foreach ($routes as $route) {
            if ($route instanceof ExpressionCollection) {
                $prefix = \substr($route->getPrefix(), $prefixLen);
                $regexpCode = $this->compileExpressionCollection($route, $prefixLen + \strlen($prefix), $vars);

                $code .= "\n        ." . self::export("|{$prefix}(?") . self::indent($regexpCode) . "\n        .')'";

                continue;
            }

            $code .= "\n        .";
            $code .= self::export(\sprintf('|%s(*:%s)', \substr(\array_shift($route), $prefixLen), $name = \array_shift($route)));

            $vars[$name] = $route;
        }

        return $code;
    }

    /**
     * @return mixed[]
     */
    private function filterExpression(string $expression): array
    {
        \preg_match('/\^(.*)\$/', $expression, $matches);

        if (!isset($matches[1])) {
            return [null, []];
        }

        $modifiers = [];
        $pattern = \preg_replace_callback(
            '/\?P<(\w+)>/',
            static function (array $matches) use (&$modifiers): string {
                $modifiers[] = $matches[1];

                return '';
            },
            $matches[1]
        );

        return [$pattern, $modifiers];
    }

    /**
     * @internal
     */
    private function generateCompiledRoutes(): string
    {
        $code = '[ // $staticRoutes' . "\n";

        foreach ($this->staticRoutes as $path => $route) {
            $code .= \sprintf('    %s => ', self::export($path));
            $code .= self::export($route);
            $code .= ",\n";
        }
        $code .= "],\n";

        [$regex, $variables] = $this->regexpList;
        $regexpCode = "    {$regex},\n    [\n";

        foreach ($variables as $key => $value) {
            $regexpCode .= \sprintf('        %s => ', self::export($key));
            $regexpCode .= self::export($value, 3);
            $regexpCode .= ",\n";
        }

        $code .= \sprintf("[ // \$regexpList\n%s    ],\n],\n", $regexpCode);

        $code .= '[ // $reversedRoutes' . "\n";

        foreach ($this->urlsList as $path => $route) {
            $code .= \sprintf('    %s => ', self::export($path));
            $code .= self::export($route);
            $code .= ",\n";
        }
        $code .= "],\n";

        $code .= '[ // $routeCollection' . "\n";

        foreach ($this->routeList as $name => $route) {
            $code .= \sprintf('    %s => ', self::export($name));
            $code .= self::export($route);
            $code .= ",\n";
        }
        $code .= "],\n";

        return self::indent($code);
    }
}

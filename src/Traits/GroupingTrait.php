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

namespace Flight\Routing\Traits;

use Biurad\Annotations\LoaderInterface;
use Flight\Routing\{DebugRoute, Route};
use Flight\Routing\Interfaces\RouteCompilerInterface;

trait GroupingTrait
{
    /** @var array */
    private $cacheData = null;

    /** @var self|null */
    private $parent = null;

    /** @var array<string,mixed[]>|null */
    private $stack = null;

    /** @var int */
    private $countRoutes = 0;

    /** @var DebugRoute|null */
    private $profiler = null;

    /** @var RouteCompilerInterface */
    private $compiler;

    /**
     * If routes was debugged, return the profiler.
     */
    public function getDebugRoute(): ?DebugRoute
    {
        return $this->profiler;
    }

    /**
     * Load routes from annotation.
     */
    public function loadAnnotation(LoaderInterface $loader): void
    {
        $annotations = $loader->load();

        foreach ($annotations as $annotation) {
            if ($annotation instanceof self) {
                $this['group'][] = $annotation;
            }
        }
    }

    /**
     * Bind route with collection.
     */
    private function resolveWith(Route $route): Route
    {
        if (null !== $stack = $this->stack) {
            foreach ($stack as $routeMethod => $arguments) {
                if (empty($arguments)) {
                    continue;
                }

                \call_user_func_array([$route, $routeMethod], 'prefix' === $routeMethod ? [\implode('', $arguments)] : $arguments);
            }
        }

        if (null === $this->parent) {
            $this->processRouteMaps($route, $this->countRoutes, $this);

            if (null !== $this->profiler) {
                $this->profiler->addProfile($route);
            }
        } else {
            $route->belong($this); // Attach grouping to route.
        }

        ++$this->countRoutes;

        return $route;
    }

    /**
     * @param \ArrayIterator<string,mixed> $routes
     *
     * @return array<int,Route>
     */
    private function doMerge(string $prefix, self $routes, bool $merge = true): void
    {
        $unnamedRoutes = [];

        foreach ($this->offsetGet('group') as $namePrefix => $group) {
            $prefix .= \is_string($namePrefix) ? $namePrefix : '';

            foreach ($group['routes'] ?? [] as $route) {
                if (null === $name = $route->get('name')) {
                    $name = $route->generateRouteName('');

                    if (isset($unnamedRoutes[$name])) {
                        $name .= ('_' !== $name[-1] ? '_' : '') . ++$unnamedRoutes[$name];
                    } else {
                        $unnamedRoutes[$name] = 0;
                    }
                }

                $routes['routes'][] = $route->bind($prefix . $name);

                if (null !== $routes->profiler) {
                    $routes->profiler->addProfile($route);
                }

                $this->processRouteMaps($route, $routes->countRoutes, $routes);

                ++$routes->countRoutes;
            }

            if ($group->offsetExists('group')) {
                $group->doMerge($prefix, $routes, false);
            }
        }

        if ($merge) {
            $routes->offsetUnset('group'); // Unset grouping ...
        }
    }

    /**
     * @param \ArrayIterator|array $routes
     */
    private function processRouteMaps(Route $route, int $routeId, \ArrayIterator $routes): void
    {
        [$pathRegex, $hostsRegex, $variables] = $this->compiler->compile($route);

        if ('\\' === $pathRegex[0]) {
            $routes['dynamicRoutesMap'][0][] = \preg_replace('/\?(?|P<\w+>|<\w+>|\'\w+\')/', '', (empty($hostsRegex) ? '(?:\\/{2}[^\/]+)?' : '\\/{2}(?i:(?|' . \implode('|', $hostsRegex) . '))') . $pathRegex) . '(*:' . $routeId . ')';
            $routes['dynamicRoutesMap'][1][$routeId] = $variables;
        } else {
            $routes['staticRoutesMap'][$pathRegex] = [$routeId, !empty($hostsRegex) ? '#^(?|' . \implode('|', $hostsRegex) . ')$#i' : null, $variables];
        }
    }

    private function generateRouteName(Route $route, array $unnamedRoutes): string
    {
        if (null === $name = $route->get('name')) {
            $name = $route->generateRouteName('');

            if (isset($unnamedRoutes[$name])) {
                $name .= ('_' !== $name[-1] ? '_' : '') . ++$unnamedRoutes[$name];
            } else {
                $unnamedRoutes[$name] = 0;
            }
        }

        return $name;
    }
}

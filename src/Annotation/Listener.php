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

namespace Flight\Routing\Annotation;

use Biurad\Annotations\InvalidAnnotationException;
use Biurad\Annotations\ListenerInterface;
use Biurad\Annotations\Locate\{Annotation, Class_, Function_, Method};
use Flight\Routing\Route as BaseRoute;
use Flight\Routing\RouteCollection;

class Listener implements ListenerInterface
{
    /** @var RouteCollection */
    private $collector;

    /** @var int */
    private $defaultUnnamedIndex = 0;

    public function __construct(?RouteCollection $collector = null)
    {
        $this->collector = $collector ?? new RouteCollection();
    }

    /**
     * {@inheritdoc}
     */
    public function load(array $annotations): RouteCollection
    {
        foreach ($annotations as $annotation) {
            if ($annotation instanceof Class_ && [] !== $annotation->methods) {
                foreach ($annotation->methods as $method) {
                    if ([] !== $attributes = $annotation->getAnnotation()) {
                        foreach ($attributes as $attribute) {
                            $this->addRoute($attribute, $method);
                        }

                        continue;
                    }

                    $this->addRoute(null, $method);
                }

                continue;
            }

            $this->addRoute(null, $annotation);
        }

        return $this->collector;
    }

    /**
     * {@inheritdoc}
     */
    public function getAnnotation(): string
    {
        return 'Flight\Routing\Annotation\Route';
    }

    /**
     * Add a route from annotation.
     *
     * @param Function_|Method|Class_ $listener
     */
    protected function addRoute(?Route $routeAnnotation, Annotation $listener): void
    {
        $controller = $listener->getReflection()->getName();

        if ($listener instanceof Method) {
            $controller = [$listener->getReflection()->class, $controller];
        }

        /** @var Route $annotation */
        foreach ($listener->getAnnotation() as $annotation) {
            if ($routeAnnotation instanceof Route) {
                [$prefixName, $prefixPath] = [$routeAnnotation->name, $routeAnnotation->path];

                // CLone annotations on class ...
                $annotation->clone($routeAnnotation);
            }

            if (null === $annotation->path) {
                throw new InvalidAnnotationException('@Route.path must not be left empty.');
            }

            $route = BaseRoute::__set_state([
                'path' => $annotation->path,
                'controller' => $controller,
                'methods' => $annotation->methods,
                'schemes' => $annotation->schemes,
                'middlewares' => $annotation->middlewares,
                'patterns' => $annotation->patterns,
                'defaults' => $annotation->defaults,
            ]);
            $route->domain(...$annotation->domain);

            if (empty($name = $annotation->name)) {
                $name = $base = $route->generateRouteName('annotated_');

                while ($this->collector->find($name)) {
                    $name = $base . '_' . ++$this->defaultUnnamedIndex;
                }
            }

            if (isset($prefixPath)) {
                $route->prefix($prefixPath);
            }

            $this->collector->add($route->bind(isset($prefixName) ? $prefixName .= $name : $name));
        }
    }
}

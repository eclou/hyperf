<?php

namespace Hyperf\Autoload;


use Hyperf\Di\Annotation\AnnotationCollector;
use Hyperf\Di\Annotation\AspectCollector;
use Roave\BetterReflection\Reflection\ReflectionClass;

class ProxyManager
{

    public static function init(array $classes = []): array
    {
        $proxies = static::initProxyList($classes);
        return static::generateProxyFiles($proxies);
    }

    private static function generateProxyFiles(array $proxies = []): array
    {
        $proxyFiles = [];
        $proxyFileDir = BASE_PATH . '/runtime/container/proxy/';
        if (! file_exists($proxyFileDir)) {
            mkdir($proxyFileDir, 0755, true);
        }
        $ast = new Ast();
        foreach ($proxies as $className => $aspects) {
            $code = $ast->proxy($className, $className);
            $proxyFilePath = $proxyFileDir . md5($code) . '.php';
            if (! file_exists($proxyFilePath)) {
                file_put_contents($proxyFilePath, $code);
            }
            $proxyFiles[$className] = $proxyFilePath;
        }
        return $proxyFiles;
    }

    private static function initProxyList(array $classes = [])
    {
        // According to the data of AspectCollector to parse all the classes that need proxy.
        $proxies = [];
        $classesAspects = AspectCollector::get('classes', []);
        foreach ($classesAspects as $aspect => $rules) {
            foreach ($rules as $rule) {
                foreach ($classes as $class) {
                    if ($class instanceof ReflectionClass) {
                        if (static::isMatch($rule, $class->getName())) {
                            $proxies[$class->getName()][] = $aspect;
                        }
                    }
                }
            }
        }

        foreach ($classes as $class) {
            $className = $class->getName();
            // Get the controller annotations.
            $classAnnotations = value(function () use ($className) {
                $annotations = AnnotationCollector::get($className . '._c', []);
                return array_keys($annotations);
            });
            // Aggregate all methods annotations.
            $methodAnnotations = value(function () use ($className) {
                $defined = [];
                $annotations = AnnotationCollector::get($className . '._m', []);
                foreach ($annotations as $method => $annotation) {
                    $defined = array_merge($defined, array_keys($annotation));
                }
                return $defined;
            });
            $annotations = array_unique(array_merge($classAnnotations, $methodAnnotations));
            if ($annotations) {
                $annotationsAspects = AspectCollector::get('annotations', []);
                foreach ($annotationsAspects as $aspect => $rules) {
                    foreach ($rules as $rule) {
                        foreach ($annotations as $annotation) {
                            if (static::isMatch($rule, $annotation)) {
                                $proxies[$className][] = $aspect;
                            }
                        }
                    }
                }
            }
        }
        return $proxies;
    }

    private static function isMatch(string $rule, string $target): bool
    {
        if (strpos($rule, '::') !== false) {
            [$rule,] = explode('::', $rule);
        }
        if (strpos($rule, '*') === false && $rule === $target) {
            return true;
        }
        $preg = str_replace(['*', '\\'], ['.*', '\\\\'], $rule);
        $pattern = "/^{$preg}$/";

        if (preg_match($pattern, $target)) {
            return true;
        }

        return false;
    }

}

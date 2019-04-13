<?php

/*
 * This file is part of the NelmioApiDocBundle package.
 *
 * (c) Nelmio
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Nelmio\ApiDocBundle\Model;

use Nelmio\ApiDocBundle\Describer\ModelRegistryAwareInterface;
use Nelmio\ApiDocBundle\ModelDescriber\ModelDescriberInterface;
use Nelmio\ApiDocBundle\SwaggerPhp\Util;
use OpenApi\Annotations\OpenApi;
use Symfony\Component\PropertyInfo\Type;

final class ModelRegistry
{
    private $alternativeNames = [];

    private $unregistered = [];

    private $models = [];

    private $names = [];

    private $modelDescribers = [];

    private $api;

    /**
     * @param ModelDescriberInterface[]|iterable $modelDescribers
     * @param OpenApi                            $api
     * @param array                              $alternativeNames
     *
     * @internal
     */
    public function __construct($modelDescribers, OpenApi $api, array $alternativeNames = [])
    {
        $this->modelDescribers = $modelDescribers;
        $this->api = $api;
        $this->alternativeNames = []; // last rule wins

        foreach (array_reverse($alternativeNames) as $alternativeName => $criteria) {
            $this->alternativeNames[] = $model = new Model(new Type('object', false, $criteria['type']), $criteria['groups']);
            $this->names[$model->getHash()] = $alternativeName;
            Util::getDefinition($api, $alternativeName);
        }
    }

    public function register(Model $model): string
    {
        $hash = $model->getHash();
        if (!isset($this->models[$hash])) {
            $this->models[$hash] = $model;
            $this->unregistered[] = $hash;
        }
        if (!isset($this->names[$hash])) {
            $this->names[$hash] = $this->generateModelName($model);
        }

        // Reserve the name
        Util::getDefinition($this->api, $this->names[$hash]);

        return '#/definitions/'.$this->names[$hash];
    }

    /**
     * @internal
     */
    public function registerDefinitions()
    {
        while (count($this->unregistered)) {
            $tmp = [];
            foreach ($this->unregistered as $hash) {
                $tmp[$this->names[$hash]] = $this->models[$hash];
            }
            $this->unregistered = [];

            foreach ($tmp as $name => $model) {
                $definition = null;
                foreach ($this->modelDescribers as $modelDescriber) {
                    if ($modelDescriber instanceof ModelRegistryAwareInterface) {
                        $modelDescriber->setModelRegistry($this);
                    }
                    if ($modelDescriber->supports($model)) {
                        $definition = Util::getDefinition($this->api, $name);
                        $modelDescriber->describe($model, $definition);

                        break;
                    }
                }

                if (null === $definition) {
                    throw new \LogicException(sprintf('Definition of type "%s" can\'t be generated, no describer supports it.', $this->typeToString($model->getType())));
                }
            }
        }

        if (empty($this->unregistered) && !empty($this->alternativeNames)) {
            foreach ($this->alternativeNames as $model) {
                $this->register($model);
            }
            $this->alternativeNames = [];
            $this->registerDefinitions();
        }
    }

    private function generateModelName(Model $model): string
    {
        $name = $base = $this->getTypeShortName($model->getType());
        $names = array_column($this->api->definitions ?: [], 'definition');
        $i = 1;
        while (\in_array($name, $names, true)) {
            ++$i;
            $name = $base.$i;
        }

        return $name;
    }

    private function getTypeShortName(Type $type): string
    {
        if (null !== $type->getCollectionValueType()) {
            return $this->getTypeShortName($type->getCollectionValueType()).'[]';
        }

        if (Type::BUILTIN_TYPE_OBJECT === $type->getBuiltinType()) {
            $parts = explode('\\', $type->getClassName());

            return end($parts);
        }

        return $type->getBuiltinType();
    }

    private function typeToString(Type $type): string
    {
        if (Type::BUILTIN_TYPE_OBJECT === $type->getBuiltinType()) {
            return $type->getClassName();
        }

        if ($type->isCollection()) {
            if (null !== $type->getCollectionValueType()) {
                return $this->typeToString($type->getCollectionValueType()).'[]';
            }

            return 'mixed[]';
        }

        return $type->getBuiltinType();
    }
}

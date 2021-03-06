<?php

declare(strict_types=1);

namespace NunoMaduro\Larastan\Properties;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Iterator;
use PHPStan\Parser\CachedParser;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Reflection\Annotations\AnnotationsPropertiesClassReflectionExtension;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\PropertiesClassReflectionExtension;
use PHPStan\Reflection\PropertyReflection;
use PHPStan\Type\BooleanType;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use SplFileInfo;

/**
 * @internal
 */
final class ModelPropertyExtension implements PropertiesClassReflectionExtension
{
    /** @var CachedParser */
    private $parser;

    /** @var SchemaTable[] */
    private $tables = [];

    /** @var TypeStringResolver */
    private $stringResolver;

    /** @var string */
    private $dateClass;

    /** @var AnnotationsPropertiesClassReflectionExtension */
    private $annotationExtension;

    public function __construct(CachedParser $parser, TypeStringResolver $stringResolver, AnnotationsPropertiesClassReflectionExtension $annotationExtension)
    {
        $this->parser = $parser;
        $this->stringResolver = $stringResolver;
        $this->annotationExtension = $annotationExtension;
    }

    public function hasProperty(ClassReflection $classReflection, string $propertyName): bool
    {
        if (! $classReflection->isSubclassOf(Model::class)) {
            return false;
        }

        if ($classReflection->isAbstract()) {
            return false;
        }

        if ($classReflection->hasNativeMethod('get'.Str::studly($propertyName).'Attribute')) {
            return false;
        }

        if ($this->annotationExtension->hasProperty($classReflection, $propertyName)) {
            return false;
        }

        if (count($this->tables) === 0) {
            $this->initializeTables();
        }

        if ($propertyName === 'id') {
            return true;
        }

        /** @var Model $modelInstance */
        $modelInstance = $classReflection->getNativeReflection()->newInstance();
        $tableName = $modelInstance->getTable();

        if (! array_key_exists($tableName, $this->tables)) {
            return false;
        }

        if (! array_key_exists($propertyName, $this->tables[$tableName]->columns)) {
            return false;
        }

        $this->castPropertiesType($modelInstance);

        $column = $this->tables[$tableName]->columns[$propertyName];

        [$readableType, $writableType] = $this->getReadableAndWritableTypes($column, $modelInstance);

        $column->readableType = $readableType;
        $column->writeableType = $writableType;

        $this->tables[$tableName]->columns[$propertyName] = $column;

        return true;
    }

    public function getProperty(
        ClassReflection $classReflection,
        string $propertyName
    ): PropertyReflection {
        /** @var Model $modelInstance */
        $modelInstance = $classReflection->getNativeReflection()->newInstance();
        $tableName = $modelInstance->getTable();

        if (
            (! array_key_exists($tableName, $this->tables)
                || ! array_key_exists($propertyName, $this->tables[$tableName]->columns)
            )
            && $propertyName === 'id'
        ) {
            return new ModelProperty(
                $classReflection,
                new IntegerType(),
                new IntegerType()
            );
        }

        $column = $this->tables[$tableName]->columns[$propertyName];

        $readableType = $column->readableType instanceof Type ? $column->readableType : $this->stringResolver->resolve($column->readableType);
        $writeableType = $column->writeableType instanceof Type ? $column->writeableType : $this->stringResolver->resolve($column->writeableType);

        return new ModelProperty(
            $classReflection,
            $readableType,
            $writeableType
        );
    }

    private function initializeTables(): void
    {
        if (! is_dir(database_path().'/migrations')) {
            return;
        }

        $schemaAggregator = new SchemaAggregator();
        $files = $this->getMigrationFiles(database_path().'/migrations');
        $filesArray = iterator_to_array($files);
        ksort($filesArray);

        $this->requireFiles($filesArray);

        foreach ($filesArray as $file) {
            $schemaAggregator->addStatements($this->parser->parseFile($file->getPathname()));
        }

        $this->tables = $schemaAggregator->tables;
    }

    /**
     * @param string $path
     *
     * @return Iterator<SplFileInfo>
     */
    private function getMigrationFiles(string $path): Iterator
    {
        return new RegexIterator(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path)), '/\.php$/i');
    }

    /**
     * @param SplFileInfo[] $files
     */
    private function requireFiles(array $files): void
    {
        foreach ($files as $file) {
            require_once $file;
        }
    }

    private function getDateClass(): string
    {
        if (! $this->dateClass) {
            $this->dateClass = class_exists(\Illuminate\Support\Facades\Date::class)
                ? '\\'.get_class(\Illuminate\Support\Facades\Date::now())
                : '\Illuminate\Support\Carbon';

            $this->dateClass .= '|\Carbon\Carbon';
        }

        return $this->dateClass;
    }

    /**
     * @param SchemaColumn $column
     * @param Model $modelInstance
     *
     * @return string[]
     * @phpstan-return array<int, string|Type>
     */
    private function getReadableAndWritableTypes(SchemaColumn $column, Model $modelInstance): array
    {
        $readableType = 'mixed';
        $writableType = 'mixed';

        if (in_array($column->name, $modelInstance->getDates(), true)) {
            return [$this->getDateClass().($column->nullable ? '|null' : ''), $this->getDateClass().'|string'.($column->nullable ? '|null' : '')];
        }

        switch ($column->readableType) {
            case 'string':
            case 'int':
            case 'float':
                /** @var string $type */
                $type = $column->readableType;
                $readableType = $writableType = $type.($column->nullable ? '|null' : '');
                break;

            case 'boolean':
            case 'bool':
                $readableType = new BooleanType();
                $writableType = TypeCombinator::union(new BooleanType(), new ConstantIntegerType(0), new ConstantIntegerType(1));
                break;
            case 'enum':
                if (! $column->options) {
                    $readableType = $writableType = 'string';
                } else {
                    $readableType = $writableType = '\''.implode('\'|\'', $column->options).'\'';
                }

                break;

            default:
                break;
        }

        return [$readableType, $writableType];
    }

    private function castPropertiesType(Model $modelInstance): void
    {
        $casts = $modelInstance->getCasts();
        foreach ($casts as $name => $type) {
            switch ($type) {
                case 'boolean':
                case 'bool':
                    $readableType = new BooleanType();
                    $writableType = TypeCombinator::union(new BooleanType(), new ConstantIntegerType(0), new ConstantIntegerType(1));
                    break;
                case 'string':
                    $readableType = $writableType = 'string';
                    break;
                case 'array':
                case 'json':
                    $readableType = $writableType = 'array';
                    break;
                case 'object':
                    $readableType = $writableType = 'object';
                    break;
                case 'int':
                case 'integer':
                case 'timestamp':
                    $readableType = $writableType = 'integer';
                    break;
                case 'real':
                case 'double':
                case 'float':
                    $readableType = $writableType = 'float';
                    break;
                case 'date':
                case 'datetime':
                    $readableType = $writableType = $this->dateClass;
                    break;
                case 'collection':
                    $readableType = $writableType = '\Illuminate\Support\Collection';
                    break;
                default:
                    $readableType = $writableType = class_exists($type) ? ('\\'.$type) : 'mixed';
                    break;
            }

            if (! array_key_exists($name, $this->tables[$modelInstance->getTable()]->columns)) {
                continue;
            }

            $this->tables[$modelInstance->getTable()]->columns[$name]->readableType = $readableType;
            $this->tables[$modelInstance->getTable()]->columns[$name]->writeableType = $writableType;
        }
    }
}

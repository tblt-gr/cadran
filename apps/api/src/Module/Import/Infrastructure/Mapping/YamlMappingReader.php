<?php

declare(strict_types=1);

namespace App\Module\Import\Infrastructure\Mapping;

use App\Module\Import\Application\MappingReader;
use App\Module\Import\Domain\Mapping\ImportMapping;
use App\Module\Import\Domain\Mapping\InvalidMapping;
use App\Module\Import\Domain\Mapping\MappingFactory;
use App\Module\Import\Domain\Mapping\MappingProblem;
use App\Module\Import\Domain\Mapping\MappingProblemCode;
use App\Module\Import\Domain\WorkbookUsageError;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads the YAML mapping with no object, constant or tag interpretation. A
 * parser error is reported by line only: its message can quote the content.
 */
final class YamlMappingReader implements MappingReader
{
    private const int MAX_BYTES = 1024 * 1024;

    public function read(string $path): ImportMapping
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new WorkbookUsageError('The mapping file does not exist or is not readable.');
        }
        $size = filesize($path);
        if (false === $size || $size > self::MAX_BYTES) {
            throw new WorkbookUsageError('The mapping file is larger than 1 MB.');
        }
        $content = file_get_contents($path);
        if (false === $content) {
            throw new WorkbookUsageError('The mapping file does not exist or is not readable.');
        }

        return $this->fromYaml($content);
    }

    /**
     * @throws InvalidMapping
     */
    public function fromYaml(string $yaml): ImportMapping
    {
        $duplicateLine = null;
        // Symfony 7.4 only deprecates a duplicated key and silently keeps one value: for a mapping that is a lost entry.
        set_error_handler(static function (int $severity, string $message) use (&$duplicateLine): bool {
            if (1 === preg_match('/Duplicate key .* on line (\d+)/', $message, $matches)) {
                $duplicateLine = (int) $matches[1];

                return true;
            }

            return false;
        }, \E_USER_DEPRECATED);

        try {
            $document = Yaml::parse($yaml, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $exception) {
            $line = $exception->getParsedLine();
            $duplicate = str_contains($exception->getMessage(), 'Duplicate key');

            throw new InvalidMapping([new MappingProblem($duplicate ? 'line '.$line : 'line '.max(0, $line), $duplicate ? MappingProblemCode::DUPLICATE_KEY : MappingProblemCode::YAML_SYNTAX)]);
        } finally {
            restore_error_handler();
        }

        if (null !== $duplicateLine) {
            throw new InvalidMapping([new MappingProblem('line '.$duplicateLine, MappingProblemCode::DUPLICATE_KEY)]);
        }

        return MappingFactory::fromDocument($document);
    }
}

<?php

declare(strict_types=1);

namespace YouTubeMcp\Mcp;

use Opis\JsonSchema\Validator;
use YouTubeMcp\ServiceError;

final class Arguments
{
    /**
     * @param array<array-key, mixed> $arguments
     * @param array<string, array<string, mixed>> $properties
     * @param list<string> $required
     */
    public static function validate(array $arguments, array $properties, array $required): void
    {
        $schemaDefinition = [
            'type' => 'object',
            'properties' => $properties ?: new \stdClass(),
            'required' => $required,
            'additionalProperties' => false,
        ];
        $schema = json_decode(json_encode($schemaDefinition, JSON_THROW_ON_ERROR), false, 32, JSON_THROW_ON_ERROR);
        $data = json_decode(json_encode((object) $arguments, JSON_THROW_ON_ERROR), false, 32, JSON_THROW_ON_ERROR);

        if (!$schema instanceof \stdClass) {
            throw new \RuntimeException('Invalid tool schema.');
        }
        if (!(new Validator())->validate($data, $schema)->isValid()) {
            throw new ServiceError('INVALID_ARGUMENT', 'Arguments do not match this tool schema.');
        }
    }
}

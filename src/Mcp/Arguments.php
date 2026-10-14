<?php

declare(strict_types=1);

namespace YouTubeMusicMcp\Mcp;

use Opis\JsonSchema\Validator;
use YouTubeMusicMcp\ServiceError;

final class Arguments
{
    public static function validate(array $arguments, array $properties, array $required): void
    {
        $schema = json_decode(json_encode(['type' => 'object', 'properties' => $properties ?: new \stdClass(),
            'required' => $required, 'additionalProperties' => false], JSON_THROW_ON_ERROR), false, 32, JSON_THROW_ON_ERROR);
        $data = json_decode(json_encode((object)$arguments, JSON_THROW_ON_ERROR), false, 32, JSON_THROW_ON_ERROR);
        if (!(new Validator())->validate($data, $schema)->isValid()) {
            throw new ServiceError('INVALID_ARGUMENT', 'Arguments do not match this tool schema.');
        }
    }
}

<?php

declare(strict_types=1);

namespace YouTubeMcp\Routes;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use YouTubeMcp\Auth\OAuth;
use YouTubeMcp\Data;
use YouTubeMcp\Http\RequestChecks;
use YouTubeMcp\Http\Responses;
use YouTubeMcp\ServiceError;

/**
 * Accepts dynamic OAuth client registration requests.
 *
 * Parses POST bodies and delegates registration policy and persistence to OAuth.
 */
final class RegisterClientRoute
{
    public function __construct(
        private OAuth $oauth,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        RequestChecks::post($request);
        try {
            $data = Data::object(RequestChecks::body($request));
        } catch (\UnexpectedValueException) {
            throw new ServiceError('INVALID_REQUEST', 'Expected an object.');
        }

        return Responses::json($this->oauth->register($data, public: true), 201);
    }
}

<?php

declare(strict_types=1);

namespace App\Http;

use App\Exception\ApiException;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Fills a request-input object from decoded JSON and validates it.
 *
 * The input classes are plain objects with public typed properties and Symfony
 * Validator constraints. Assigning a value of the wrong JSON type (an array for
 * a string field, `null` for a required one) makes PHP throw `TypeError`; that
 * is translated into the same `422 validation_failed` envelope as a constraint
 * violation, so a client never sees a 500 for a well-formed but wrong-typed
 * body.
 */
final class InputHydrator
{
    public function __construct(private readonly ValidatorInterface $validator)
    {
    }

    /**
     * @template T of object
     *
     * @param class-string<T>      $class
     * @param array<string, mixed> $data
     *
     * @return T
     */
    public function hydrate(string $class, array $data): object
    {
        $input = new $class();
        $reflection = new \ReflectionClass($input);

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if (!array_key_exists($property->getName(), $data)) {
                continue;
            }

            try {
                $property->setValue($input, $data[$property->getName()]);
            } catch (\TypeError) {
                throw ApiException::validationFailed('The request contains invalid fields.', [$property->getName() => ['This value has the wrong type.']]);
            }
        }

        $violations = $this->validator->validate($input);
        if (count($violations) > 0) {
            throw ApiException::validationFailed('The request contains invalid fields.', $this->fields($violations));
        }

        return $input;
    }

    /**
     * @param iterable<ConstraintViolationInterface> $violations
     *
     * @return array<string, list<string>>
     */
    private function fields(iterable $violations): array
    {
        $fields = [];
        foreach ($violations as $violation) {
            $fields[$violation->getPropertyPath()][] = (string) $violation->getMessage();
        }

        return $fields;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Input;

use App\Input\RegisterInput;
use App\Input\ResetPasswordInput;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Validator\ValidatorBuilder;

/**
 * The password policy is a product rule, not a database one, so it is asserted
 * directly on the input objects the controller validates.
 *
 * The upper bound is 72 because bcrypt silently truncates past it: a longer
 * password would be accepted and then only partly checked at sign-in.
 */
final class PasswordPolicyTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = (new ValidatorBuilder())->enableAttributeMapping()->getValidator();
    }

    public function testTheMinimumLengthIsTenCharacters(): void
    {
        self::assertCount(1, $this->violations($this->register(password: str_repeat('a', 9))));
        self::assertCount(0, $this->violations($this->register(password: str_repeat('a', 10))));
    }

    public function testTheMaximumLengthIsSeventyTwoCharacters(): void
    {
        self::assertCount(0, $this->violations($this->register(password: str_repeat('a', 72))));

        $violations = $this->violations($this->register(password: str_repeat('a', 73)));
        self::assertCount(1, $violations);
        self::assertSame('password', $violations->get(0)->getPropertyPath());
        self::assertStringContainsString('72', $violations->get(0)->getMessage());
    }

    public function testTheResetFormEnforcesTheSamePasswordBounds(): void
    {
        $short = new ResetPasswordInput();
        $short->token = 'a-token';
        $short->password = 'short';

        $violations = $this->validator->validate($short);
        self::assertCount(1, $violations);
        self::assertSame('password', $violations->get(0)->getPropertyPath());
    }

    public function testAnInvalidAddressIsRejected(): void
    {
        $violations = $this->violations($this->register(email: 'not-an-address'));

        self::assertCount(1, $violations);
        self::assertSame('email', $violations->get(0)->getPropertyPath());
    }

    public function testTheOrganizationNameBoundsAreTwoToAHundredAndTwenty(): void
    {
        self::assertCount(1, $this->violations($this->register(organizationName: 'a')));
        self::assertCount(0, $this->violations($this->register(organizationName: 'ab')));
        self::assertCount(0, $this->violations($this->register(organizationName: str_repeat('a', 120))));
        self::assertCount(1, $this->violations($this->register(organizationName: str_repeat('a', 121))));
    }

    public function testTheDisplayNameIsOptionalButBounded(): void
    {
        self::assertCount(0, $this->violations($this->register(displayName: null)));
        self::assertCount(0, $this->violations($this->register(displayName: str_repeat('a', 120))));
        self::assertCount(1, $this->violations($this->register(displayName: str_repeat('a', 121))));
    }

    public function testAValidRegistrationHasNoViolations(): void
    {
        self::assertCount(0, $this->violations($this->register()));
    }

    private function register(
        ?string $email = null,
        string $password = 'Correct-Horse-9',
        string $organizationName = 'Northwind',
        ?string $displayName = 'Amina',
    ): RegisterInput {
        $input = new RegisterInput();
        $input->email = $email ?? 'amina@example.test';
        $input->password = $password;
        $input->organizationName = $organizationName;
        $input->displayName = $displayName;

        return $input;
    }

    private function violations(RegisterInput $input): ConstraintViolationListInterface
    {
        return $this->validator->validate($input);
    }
}

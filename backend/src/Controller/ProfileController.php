<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\ApiException;
use App\Http\InputHydrator;
use App\Http\JsonBody;
use App\Input\ChangePasswordInput;
use App\Input\UpdateProfileInput;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The signed-in person's own account.
 *
 * A password change signs every other session out: Symfony compares the password
 * hash stored in each session's token with the current one on the next request.
 * This session is kept by rewriting its token with the new hash — otherwise the
 * person who just changed their password would be signed out by their own action.
 */
final class ProfileController extends AbstractController
{
    use CurrentPrincipalTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InputHydrator $hydrator,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    #[Route('/api/me', name: 'app_api_me_update', methods: ['PATCH'])]
    public function update(Request $request): JsonResponse
    {
        $principal = $this->currentPrincipal();
        $input = $this->hydrator->hydrate(UpdateProfileInput::class, JsonBody::decode($request));

        $principal->changeDisplayName($input->displayName);
        $this->entityManager->flush();

        return $this->json(['user' => $this->serialize()], Response::HTTP_OK, ['Cache-Control' => 'no-store, private']);
    }

    #[Route('/api/me/password', name: 'app_api_me_password', methods: ['POST'])]
    public function changePassword(Request $request): Response
    {
        $principal = $this->currentPrincipal();
        $input = $this->hydrator->hydrate(ChangePasswordInput::class, JsonBody::decode($request));

        if (!$this->passwordHasher->isPasswordValid($principal, $input->currentPassword)) {
            // The field is named so a form can point at it; the message never
            // confirms anything about the account beyond "this did not match".
            throw ApiException::validationFailed('The request contains invalid fields.', ['currentPassword' => ['The current password is incorrect.']]);
        }

        $principal->setPasswordHash($this->passwordHasher->hashPassword($principal, $input->newPassword));
        $this->entityManager->flush();

        // Keep this session, invalidate the others (their serialized token still
        // carries the previous hash).
        $token = $this->tokenStorage->getToken();
        $token?->setUser($principal);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /** @return array{id: string, email: string, displayName: string|null} */
    private function serialize(): array
    {
        $principal = $this->currentPrincipal();

        return [
            'id' => $principal->getId(),
            'email' => $principal->getEmail(),
            'displayName' => $principal->getDisplayName(),
        ];
    }
}

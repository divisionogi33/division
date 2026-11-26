<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $em
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_calendar');
        }

        $error = null;

        if ($request->isMethod('POST')) {
            $email = trim((string) $request->request->get('email'));
            $plainPassword = (string) $request->request->get('password');
            $firstName = trim((string) $request->request->get('firstName'));
            $lastName = trim((string) $request->request->get('lastName'));
            $rank = trim((string) $request->request->get('militaryRank'));
            $position = trim((string) $request->request->get('position'));

            if ($em->getRepository(User::class)->findOneBy(['email' => $email])) {
                $error = 'Вече съществува потребител с този имейл.';
            } elseif (strlen($plainPassword) < 6) {
                $error = 'Паролата трябва да е поне 6 символа.';
            } else {
                $isFirstUser = $em->getRepository(User::class)->count([]) === 0;

                $user = (new User())
                    ->setEmail($email)
                    ->setFirstName($firstName)
                    ->setLastName($lastName)
                    ->setMilitaryRank($rank)
                    ->setPosition($position)
                    ->setRoles($isFirstUser ? ['ROLE_ADMIN'] : ['ROLE_USER'])
                    ->setIsApproved($isFirstUser); // Първият е админ и одобрен веднага

                $user->setPassword($passwordHasher->hashPassword($user, $plainPassword));

                $em->persist($user);
                $em->flush();

                return $this->redirectToRoute('app_login');
            }
        }

        return $this->render('registration/register.html.twig', [
            'error' => $error,
        ]);
    }
}

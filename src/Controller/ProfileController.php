<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class ProfileController extends AbstractController
{
    #[Route('/profile', name: 'app_profile', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('profile_update', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Невалиден токен. Опитай отново.');
                return $this->redirectToRoute('app_profile');
            }

            $firstName = trim((string) $request->request->get('first_name', ''));
            $lastName = trim((string) $request->request->get('last_name', ''));
            $newPassword = (string) $request->request->get('new_password', '');
            $confirmPassword = (string) $request->request->get('confirm_password', '');

            if ($firstName === '' || $lastName === '') {
                $this->addFlash('error', 'Името и фамилията са задължителни.');
                return $this->redirectToRoute('app_profile');
            }

            $user->setFirstName($firstName);
            $user->setLastName($lastName);

            if ($newPassword !== '') {
                if (mb_strlen($newPassword) < 6) {
                    $this->addFlash('error', 'Паролата трябва да е поне 6 символа.');
                    return $this->redirectToRoute('app_profile');
                }
                if ($newPassword !== $confirmPassword) {
                    $this->addFlash('error', 'Двете пароли не съвпадат.');
                    return $this->redirectToRoute('app_profile');
                }
                $user->setPassword($hasher->hashPassword($user, $newPassword));
            }

            $em->flush();
            $this->addFlash('success', 'Профилът е обновен успешно.');

            return $this->redirectToRoute('app_profile');
        }

        return $this->render('profile/index.html.twig', [
            'user' => $user,
        ]);
    }
}

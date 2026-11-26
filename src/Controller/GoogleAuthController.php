<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GoogleAuthController extends AbstractController
{
    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%env(GOOGLE_CLIENT_ID)%')]
        private string $clientId,
        #[Autowire('%env(GOOGLE_CLIENT_SECRET)%')]
        private string $clientSecret,
    ) {}

    #[Route('/connect/google', name: 'connect_google_start', methods: ['GET'])]
    public function start(Request $request): Response
    {
        if (trim($this->clientId) === '' || trim($this->clientSecret) === '') {
            $this->addFlash('error', 'Липсват GOOGLE_CLIENT_ID или GOOGLE_CLIENT_SECRET в .env.local.');
            return $this->redirectToRoute('app_login');
        }

        $state = bin2hex(random_bytes(16));
        $request->getSession()->set('google_oauth_state', $state);

        $redirectUri = $this->generateUrl('connect_google_check', [], UrlGeneratorInterface::ABSOLUTE_URL);

        $params = http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'prompt' => 'select_account',
        ]);

        return $this->redirect('https://accounts.google.com/o/oauth2/v2/auth?' . $params);
    }

    #[Route('/connect/google/check', name: 'connect_google_check', methods: ['GET'])]
    public function check(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
        Security $security
    ): Response {
        $sessionState = (string) $request->getSession()->get('google_oauth_state', '');
        $request->getSession()->remove('google_oauth_state');

        $state = (string) $request->query->get('state', '');
        $code = (string) $request->query->get('code', '');

        if ($code === '' || $state === '' || !hash_equals($sessionState, $state)) {
            $this->addFlash('error', 'Невалидна сесия при вход с Google. Опитай отново.');
            return $this->redirectToRoute('app_login');
        }

        $redirectUri = $this->generateUrl('connect_google_check', [], UrlGeneratorInterface::ABSOLUTE_URL);

        // 1. Разменяме кода за access_token
        $tokenResponse = $this->httpClient->request('POST', 'https://oauth2.googleapis.com/token', [
            'body' => [
                'code' => $code,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'redirect_uri' => $redirectUri,
                'grant_type' => 'authorization_code',
            ],
        ]);

        $tokenData = $tokenResponse->toArray(false);
        $accessToken = $tokenData['access_token'] ?? null;

        if (!$accessToken) {
            $this->addFlash('error', 'Google отказа автентикацията.');
            return $this->redirectToRoute('app_login');
        }

        // 2. Взимаме профила от Google
        $userResponse = $this->httpClient->request('GET', 'https://www.googleapis.com/oauth2/v3/userinfo', [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
            ],
        ]);

        $googleUser = $userResponse->toArray(false);
        $email = mb_strtolower(trim((string) ($googleUser['email'] ?? '')));

        if ($email === '') {
            $this->addFlash('error', 'Google профилът не предостави имейл адрес.');
            return $this->redirectToRoute('app_login');
        }

        // 3. Търсим съществуващ потребител или създаваме нов
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);

        if (!$user) {
            $user = new User();
            $user->setEmail($email);
            $user->setFirstName((string) ($googleUser['given_name'] ?? 'Потребител'));
            $user->setLastName((string) ($googleUser['family_name'] ?? 'Google'));
            $user->setRoles(['ROLE_USER']);
            // Случайна силна парола (не се ползва при вход с Google)
            $user->setPassword($hasher->hashPassword($user, bin2hex(random_bytes(24))));

            $em->persist($user);
            $em->flush();
        }

        // 4. Логваме потребителя директно в основния firewall ('main')
        $security->login($user, 'form_login', 'main');

        return $this->redirectToRoute('app_calendar');
    }
}

<?php

namespace App\Controller;

use App\Entity\LeaveBalance;
use App\Entity\LeaveRequest;
use App\Entity\LegendItem;
use App\Entity\Shift;
use App\Entity\Task;
use App\Entity\User;
use App\Service\HolidayService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;
use App\Service\GeminiAnalysisService;
#[Route('/api/mobile')]
class ApiMobileController extends AbstractController
{
    #[Route('/login', name: 'api_mobile_login', methods: ['POST'])]
    public function login(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher
    ): JsonResponse {
        $data = json_decode($request->getContent(), true) ?? [];
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
        $password = (string) ($data['password'] ?? '');

        if ($email === '' || $password === '') {
            return $this->mobileJson(['ok' => false, 'error' => 'Въведете имейл и парола.'], 400);
        }

        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user || !$hasher->isPasswordValid($user, $password)) {
            return $this->mobileJson(['ok' => false, 'error' => 'Грешен имейл или парола.'], 401);
        }

        if (!$user->isApproved()) {
            return $this->mobileJson(['ok' => false, 'error' => 'Профилът все още не е одобрен от администратор.'], 403);
        }

        $token = $user->getApiToken() ?: bin2hex(random_bytes(32));
        $user->setApiToken($token);
        $em->flush();

        return $this->mobileJson([
            'ok' => true,
            'token' => $token,
            'user' => $this->serializeUser($user),
        ]);
    }
    #[Route('/google-login', name: 'api_mobile_google_login', methods: ['POST'])]
    public function googleLogin(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher
    ): JsonResponse {
        $data = json_decode($request->getContent(), true) ?? [];
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->mobileJson(['ok' => false, 'error' => 'Невалиден имейл адрес.'], 400);
        }

        $emailPrefix = preg_replace('/@.*$/', '', $email);
        $defaultFirstName = $emailPrefix !== '' ? $emailPrefix : 'Потребител';

        /** @var User|null $user */
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);

        if (!$user) {
            $user = new User();
            $user->setEmail($email);
            $user->setRoles(['ROLE_USER']);
            $user->setFirstName($defaultFirstName);
            $user->setLastName('');
            if (method_exists($user, 'setIsApproved')) {
                $user->setIsApproved(true);
            }
            $user->setPassword($passwordHasher->hashPassword($user, bin2hex(random_bytes(16))));
            $em->persist($user);
        } elseif (trim((string) $user->getFirstName()) === '') {
            $user->setFirstName($defaultFirstName);
        }

        if (!$user->getApiToken()) {
            $user->setApiToken(bin2hex(random_bytes(32)));
        }

        $em->flush();

        return $this->mobileJson([
            'ok' => true,
            'token' => $user->getApiToken(),
            'user' => $this->serializeUser($user),
        ]);
    }

    #[Route('/register', name: 'api_mobile_register', methods: ['POST'])]
    public function register(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher
    ): JsonResponse {
        $data = json_decode($request->getContent(), true) ?? [];
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
        $password = (string) ($data['password'] ?? '');
        $fullName = trim((string) ($data['fullName'] ?? ''));
        $rank = trim((string) ($data['militaryRank'] ?? ''));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 4) {
            return $this->mobileJson(['ok' => false, 'error' => 'Въведете валиден имейл и парола (мин. 4 символа).'], 400);
        }

        $existing = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existing) {
            return $this->mobileJson(['ok' => false, 'error' => 'Потребител с този имейл вече съществува.'], 409);
        }

        $user = new User();
        $user->setEmail($email);
        if (method_exists($user, 'setRoles')) {
            $user->setRoles(['ROLE_USER']);
        }
        if ($fullName !== '') {
            $parts = preg_split('/\s+/u', $fullName, 2);
            $user->setFirstName($parts[0] ?? $fullName);
            $user->setLastName($parts[1] ?? '');
        } else {
            $user->setFirstName(preg_replace('/@.*$/', '', $email) ?: 'Потребител');
            $user->setLastName('');
        }
        if ($rank !== '' && method_exists($user, 'setMilitaryRank')) {
            $user->setMilitaryRank($rank);
        }
        if (method_exists($user, 'setIsApproved')) {
            $user->setIsApproved(true);
        }

        $user->setPassword($passwordHasher->hashPassword($user, $password));
        $token = bin2hex(random_bytes(32));
        $user->setApiToken($token);

        $em->persist($user);
        $em->flush();

        return $this->mobileJson([
            'ok' => true,
            'token' => $token,
            'user' => $this->serializeUser($user),
        ], 201);
    }
    #[Route('/profile', name: 'api_mobile_profile_get', methods: ['GET'])]
    public function profileGet(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->authenticate($request, $em);
        if (!$user) {
            return $this->mobileJson(['ok' => false, 'error' => 'Неоторизиран достъп.'], 401);
        }

        return $this->mobileJson([
            'ok' => true,
            'user' => $this->serializeUser($user),
        ]);
    }

    #[Route('/profile', name: 'api_mobile_profile_update', methods: ['POST'])]
    public function profileUpdate(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher
    ): JsonResponse {
        $user = $this->authenticate($request, $em);
        if (!$user) {
            return $this->mobileJson(['ok' => false, 'error' => 'Неоторизиран достъп.'], 401);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $firstName = trim((string) ($data['firstName'] ?? ''));
        $lastName = trim((string) ($data['lastName'] ?? ''));
        $newPassword = (string) ($data['newPassword'] ?? '');

        if ($firstName === '') {
            return $this->mobileJson(['ok' => false, 'error' => 'Името е задължително.'], 400);
        }

        $user->setFirstName($firstName);
        $user->setLastName($lastName);

        if ($newPassword !== '') {
            if (mb_strlen($newPassword) < 6) {
                return $this->mobileJson(['ok' => false, 'error' => 'Паролата трябва да е поне 6 символа.'], 400);
            }
            $user->setPassword($hasher->hashPassword($user, $newPassword));
        }

        $em->flush();

        return $this->mobileJson([
            'ok' => true,
            'message' => 'Профилът е обновен успешно.',
            'user' => $this->serializeUser($user),
        ]);
    }

    #[Route('/shifts', name: 'api_mobile_shifts', methods: ['GET'])]
    public function shifts(
        Request $request,
        EntityManagerInterface $em,
        HolidayService $holidayService
    ): JsonResponse {
        $user = $this->authenticate($request, $em);
        if (!$user) {
            return $this->mobileJson(['ok' => false, 'error' => 'Неоторизиран достъп.'], 401);
        }

        $isAdmin = $this->isAdminUser($user);

        $month = (string) $request->query->get('month', (new \DateTimeImmutable())->format('Y-m'));
        if (!preg_match('/^(\d{4})-(\d{2})$/', $month, $m)) {
            $month = (new \DateTimeImmutable())->format('Y-m');
            $year = (int) date('Y');
            $monthNum = (int) date('m');
        } else {
            $year = (int) $m[1];
            $monthNum = (int) $m[2];
        }

        $monthStart = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $monthNum));
        $monthEnd = $monthStart->modify('last day of this month 23:59:59');
        $normData = $holidayService->getMonthlyNorm($year, $monthNum);

        $qb = $em->getRepository(Shift::class)->createQueryBuilder('s')
            ->where('s.startDate <= :end AND s.endDate >= :start')
            ->setParameter('start', $monthStart)
            ->setParameter('end', $monthEnd)
            ->orderBy('s.startDate', 'ASC')
            ->addOrderBy('s.id', 'ASC');

        if (!$isAdmin) {
            $qb->andWhere('s.user = :user')
                ->setParameter('user', $user);
        }

        $shifts = $qb->getQuery()->getResult();

        $items = [];
        $totalHours = 0.0;

        foreach ($shifts as $s) {
            $legend = $s->getLegendItem();
            if (!$legend) {
                continue;
            }
            $totalHours += (float) $s->getCalculatedHours();

            $shiftUser = $s->getUser();
            $userName = '';
            if ($shiftUser) {
                $userName = trim(($shiftUser->getMilitaryRank() ?? '') . ' ' . ($shiftUser->getFullName() ?? ''));
                if ($userName === '') {
                    $userName = (string) $shiftUser->getEmail();
                }
            }

            $items[] = [
                'id' => $s->getId(),
                'userName' => $userName,
                'code' => (string) $legend->getCode(),
                'name' => (string) $legend->getName(),
                'color' => (string) ($legend->getColorHex() ?? '#0D6EFD'),
                'startDate' => $s->getStartDate()->format('Y-m-d'),
                'endDate' => $s->getEndDate()->format('Y-m-d'),
                'hours' => (float) $s->getCalculatedHours(),
                'location' => (string) ($s->getLocation() ?? ''),
            ];
        }

        return $this->mobileJson([
            'ok' => true,
            'isAdminView' => $isAdmin,
            'month' => $month,
            'totalHours' => round($totalHours, 1),
            'normHours' => (int) ($normData['normHours'] ?? 168),
            'shifts' => $items,
        ]);
    }
    #[Route('/admin/ai/analyze', name: 'api_mobile_admin_ai_analyze', methods: ['POST'])]
    public function adminAiAnalyze(
        Request $request,
        EntityManagerInterface $em,
        HolidayService $holidayService,
        GeminiAnalysisService $gemini
    ): JsonResponse {
        try {
            @set_time_limit(180);
            $admin = $this->authenticate($request, $em);
            if (!$admin) {
                return $this->mobileJson(['ok' => false, 'error' => 'Неоторизиран достъп.'], 401);
            }
            if (!$this->isAdminUser($admin)) {
                return $this->mobileJson(['ok' => false, 'error' => 'Нямате администраторски права.'], 403);
            }

            $data = json_decode($request->getContent(), true) ?? [];
            [$staffStats, $monthLabel, $normHours] = $this->collectMonthlyWorkloadStats(
                $em,
                $holidayService,
                (string) ($data['month'] ?? '')
            );

            $result = $gemini->analyzeWorkload($staffStats, $monthLabel, $normHours);

            return $this->mobileJson([
                'ok' => true,
                'title' => 'AI Анализ — ' . $monthLabel,
                'result' => $result,
            ]);
        } catch (\Throwable $e) {
            return $this->mobileJson(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }
    #[Route('/admin/leave-balances', name: 'api_mobile_admin_leave_balances_list', methods: ['GET'])]
    public function adminLeaveBalancesList(Request $request, EntityManagerInterface $em): JsonResponse
    {
        try {
            $admin = $this->authenticate($request, $em);
            if (!$admin) {
                return $this->mobileJson(['ok' => false, 'error' => 'Неоторизиран достъп.'], 401);
            }
            if (!$this->isAdminUser($admin)) {
                return $this->mobileJson(['ok' => false, 'error' => 'Нямате администраторски права.'], 403);
            }

            $allUsers = $em->getRepository(User::class)->findBy([], ['lastName' => 'ASC', 'firstName' => 'ASC']);
            $balances = $em->getRepository(LeaveBalance::class)->findBy([], ['year' => 'ASC']);

            $byUser = [];
            foreach ($balances as $b) {
                $uid = $b->getUser()?->getId();
                if (!$uid) {
                    continue;
                }
                $byUser[$uid][] = [
                    'year' => (int) $b->getYear(),
                    'totalDays' => (int) $b->getTotalDays(),
                    'usedDays' => (int) $b->getUsedDays(),
                    'remainingDays' => (int) $b->getRemainingDays(),
                ];
            }

            $items = [];
            foreach ($allUsers as $u) {
                if ($this->isAdminUser($u)) {
                    continue;
                }
                $uid = $u->getId();
                $label = trim(($u->getMilitaryRank() ?? '') . ' ' . ($u->getFullName() ?? ''));
                $userBals = $byUser[$uid] ?? [];
                $totalRemaining = 0;
                foreach ($userBals as $ub) {
                    $totalRemaining += $ub['remainingDays'];
                }

                $items[] = [
                    'id' => $uid,
                    'label' => $label !== '' ? $label : (string) $u->getEmail(),
                    'position' => method_exists($u, 'getPosition') ? (string) ($u->getPosition() ?? '') : '',
                    'totalRemaining' => $totalRemaining,
                    'years' => $userBals,
                ];
            }

            return $this->mobileJson([
                'ok' => true,
                'currentYear' => (int) date('Y'),
                'users' => $items,
            ]);
        } catch (\Throwable $e) {
            return $this->mobileJson(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/admin/leave-balances/set', name: 'api_mobile_admin_leave_balances_set', methods: ['POST'])]
    public function adminLeaveBalancesSet(Request $request, EntityManagerInterface $em): JsonResponse
    {
        try {
            $admin = $this->authenticate($request, $em);
            if (!$admin) {
                return $this->mobileJson(['ok' => false, 'error' => 'Неоторизиран достъп.'], 401);
            }
            if (!$this->isAdminUser($admin)) {
                return $this->mobileJson(['ok' => false, 'error' => 'Нямате администраторски права.'], 403);
            }

            $data = json_decode($request->getContent(), true) ?? [];
            $target = (string) ($data['userId'] ?? 'all');
            $year = (int) ($data['year'] ?? date('Y'));
            $totalDays = max(0, (int) ($data['totalDays'] ?? 30));
            $usedDays = max(0, (int) ($data['usedDays'] ?? 0));

            if ($year < 2000 || $year > 2100) {
                return $this->mobileJson(['ok' => false, 'error' => 'Невалидна година.'], 400);
            }

            if ($target === 'all') {
                $addedCount = 0;
                foreach ($em->getRepository(User::class)->findAll() as $u) {
                    if ($this->isAdminUser($u)) {
                        continue;
                    }
                    $bal = $em->getRepository(LeaveBalance::class)->findOneBy(['user' => $u, 'year' => $year]);
                    if (!$bal) {
                        $bal = (new LeaveBalance())
                            ->setUser($u)
                            ->setYear($year)
                            ->setTotalDays($totalDays)
                            ->setUsedDays(0);
                        $em->persist($bal);
                        $addedCount++;
                    }
                }
                $em->flush();

                return $this->mobileJson([
                    'ok' => true,
                    'message' => sprintf('Заредени са по %d дни за %d г. на %d служители без запис.', $totalDays, $year, $addedCount),
                ]);
            }

            $user = $em->getRepository(User::class)->find((int) $target);
            if (!$user || $this->isAdminUser($user)) {
                return $this->mobileJson(['ok' => false, 'error' => 'Невалиден служител.'], 400);
            }

            $bal = $em->getRepository(LeaveBalance::class)->findOneBy(['user' => $user, 'year' => $year]);
            if (!$bal) {
                $bal = (new LeaveBalance())->setUser($user)->setYear($year);
                $em->persist($bal);
            }
            $bal->setTotalDays($totalDays)->setUsedDays($usedDays);
            $em->flush();

            return $this->mobileJson([
                'ok' => true,
                'message' => sprintf('Обновен отпуск (%d г.): остават %d / %d дни.', $year, $bal->getRemainingDays(), $totalDays),
            ]);
        } catch (\Throwable $e) {
            return $this->mobileJson(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }
    #[Route('/admin/ai/export-schedule', name: 'api_mobile_admin_ai_export_schedule', methods: ['POST'])]
    public function adminAiExportSchedule(
        Request $request,
        EntityManagerInterface $em,
        HolidayService $holidayService
    ): Response {
        try {
            $admin = $this->authenticate($request, $em);
            if (!$admin) {
                return $this->mobileJson(['ok' => false, 'error' => 'Неоторизиран достъп.'], 401);
            }
            if (!$this->isAdminUser($admin)) {
                return $this->mobileJson(['ok' => false, 'error' => 'Нямате администраторски права.'], 403);
            }

            $payload = json_decode($request->getContent(), true) ?? [];
            $targetMonth = (string) ($payload['targetMonth'] ?? '');
            $schedule = $payload['schedule'] ?? [];

            if (!preg_match('/^(\d{4})-(\d{2})$/', $targetMonth, $m) || !is_array($schedule)) {
                return $this->mobileJson(['ok' => false, 'error' => 'Невалидни данни за експорт.'], 400);
            }

            $year = (int) $m[1];
            $monthNum = (int) $m[2];
            $monthStart = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $monthNum));
            $daysInMonth = (int) $monthStart->format('t');
            $normData = $holidayService->getMonthlyNorm($year, $monthNum);
            $normHours = (int) ($normData['normHours'] ?? 168);

            $legendsByCode = [];
            foreach ($em->getRepository(LegendItem::class)->findAll() as $l) {
                $legendsByCode[mb_strtoupper(trim((string) $l->getCode()))] = $l;
            }

            $usersById = [];
            foreach ($em->getRepository(User::class)->findAll() as $u) {
                $label = trim(($u->getMilitaryRank() ?? '') . ' ' . ($u->getFullName() ?? ''));
                $usersById[$u->getId()] = $label !== '' ? $label : (string) $u->getEmail();
            }

            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('AI ' . $targetMonth);

            $sheet->setCellValue([1, 1], sprintf('AI Предложение за %02d.%d г. (Норма: %d ч.)', $monthNum, $year, $normHours));
            $sheet->setCellValue([1, 3], 'Звание, Име и Фамилия');
            for ($d = 1; $d <= $daysInMonth; $d++) {
                $sheet->setCellValue([$d + 1, 3], $d);
            }
            $sheet->setCellValue([$daysInMonth + 2, 3], 'Часове');
            $sheet->setCellValue([$daysInMonth + 3, 3], 'До норма');

            $rowNum = 4;
            foreach ($schedule as $row) {
                $uid = (int) ($row['userId'] ?? 0);
                $label = $usersById[$uid] ?? ('Служител #' . $uid);
                $daysData = is_array($row['days'] ?? null) ? $row['days'] : [];

                $sheet->setCellValue([1, $rowNum], $label);
                $totalHours = 0.0;

                for ($d = 1; $d <= $daysInMonth; $d++) {
                    $val = trim((string) ($daysData[(string) $d] ?? $daysData[$d] ?? ''));
                    if ($val === '') {
                        continue;
                    }
                    $sheet->setCellValue([$d + 1, $rowNum], $val);

                    $codes = preg_split('/[\/,;\s+]+/u', $val, -1, PREG_SPLIT_NO_EMPTY);
                    foreach (array_unique($codes) as $code) {
                        $upper = mb_strtoupper(trim($code));
                        if (isset($legendsByCode[$upper])) {
                            $lg = $legendsByCode[$upper];
                            $totalHours += $lg->getDefaultDailyHours() * $lg->getMultiplier();
                        }
                    }
                }

                $hrs = round($totalHours, 1);
                $sheet->setCellValue([$daysInMonth + 2, $rowNum], $hrs);
                $sheet->setCellValue([$daysInMonth + 3, $rowNum], round($hrs - $normHours, 1));
                $rowNum++;
            }

            $tmpFile = tempnam(sys_get_temp_dir(), 'div_ai_xlsx_');
            (new Xlsx($spreadsheet))->save($tmpFile);
            $bytes = (string) file_get_contents($tmpFile);
            @unlink($tmpFile);

            return new Response($bytes, 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="ai_grafik_' . $targetMonth . '.xlsx"',
                'Content-Length' => (string) strlen($bytes),
            ]);
        } catch (\Throwable $e) {
            return $this->mobileJson(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }
    #[Route('/admin/ai/suggest', name: 'api_mobile_admin_ai_suggest', methods: ['POST'])]
    public function adminAiSuggest(
        Request $request,
        EntityManagerInterface $em,
        HolidayService $holidayService,
        GeminiAnalysisService $gemini
    ): JsonResponse {
        try {
            @set_time_limit(180);
            $admin = $this->authenticate($request, $em);
            if (!$admin) {
                return $this->mobileJson(['ok' => false, 'error' => 'Неоторизиран достъп.'], 401);
            }
            if (!$this->isAdminUser($admin)) {
                return $this->mobileJson(['ok' => false, 'error' => 'Нямате администраторски права.'], 403);
            }

            [$staffStats] = $this->collectMonthlyWorkloadStats($em, $holidayService);

            $nextMonthStart = new \DateTimeImmutable('first day of next month 00:00:00');
            $nextMonthEnd = $nextMonthStart->modify('last day of this month 23:59:59');
            $targetYear = (int) $nextMonthStart->format('Y');
            $targetMonthNum = (int) $nextMonthStart->format('m');
            $targetMonth = $nextMonthStart->format('Y-m');
            $daysInMonth = (int) $nextMonthStart->format('t');

            $normData = $holidayService->getMonthlyNorm($targetYear, $targetMonthNum);
            $normHours = (int) ($normData['normHours'] ?? 168);

            // Вече записани отпуски/болнични за следващия месец, за да ги пази Gemini
            $existingNextShifts = $em->getRepository(Shift::class)->createQueryBuilder('s')
                ->where('s.startDate <= :end AND s.endDate >= :start')
                ->setParameter('start', $nextMonthStart)
                ->setParameter('end', $nextMonthEnd)
                ->getQuery()
                ->getResult();

            $lockedDaysByUser = [];
            foreach ($existingNextShifts as $s) {
                $code = mb_strtoupper(trim($s->getLegendItem()->getCode()));
                if (!in_array($code, ['О', 'Б'], true)) {
                    continue;
                }
                $uid = $s->getUser()->getId();
                $cur = max($s->getStartDate(), $nextMonthStart);
                $end = min($s->getEndDate(), $nextMonthEnd);
                while ($cur->format('Y-m-d') <= $end->format('Y-m-d')) {
                    $lockedDaysByUser[$uid][(string) (int) $cur->format('j')] = $code;
                    $cur = $cur->modify('+1 day');
                }
            }

            $usersMap = [];
            foreach ($staffStats as &$st) {
                $uid = $st['userId'];
                $st['lockedLeaves'] = $lockedDaysByUser[$uid] ?? [];
                $usersMap[(string) $uid] = [
                    'name' => $st['name'],
                    'position' => $st['position'],
                ];
            }
            unset($st);

            $legendsMeta = [];
            foreach ($em->getRepository(LegendItem::class)->findAll() as $l) {
                $code = mb_strtoupper(trim($l->getCode()));
                $legendsMeta[$code] = [
                    'code' => $code,
                    'name' => $l->getName(),
                    'color' => $l->getColorHex(),
                    'hours' => $l->getDefaultDailyHours() * $l->getMultiplier(),
                ];
            }

            $aiData = $gemini->generateNextMonthScheduleJson($staffStats, $targetMonth, $daysInMonth, $normHours);

            return $this->mobileJson([
                'ok' => true,
                'targetMonth' => $targetMonth,
                'targetMonthLabel' => $nextMonthStart->format('m.Y'),
                'daysInMonth' => $daysInMonth,
                'workingDays' => (int) ($normData['workingDays'] ?? round($normHours / 8)),
                'normHours' => $normHours,
                'legendsMeta' => $legendsMeta,
                'usersMap' => $usersMap,
                'summary' => (string) ($aiData['summary'] ?? ''),
                'schedule' => $aiData['schedule'] ?? [],
            ]);
        } catch (\Throwable $e) {
            return $this->mobileJson(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/admin/ai/apply-schedule', name: 'api_mobile_admin_ai_apply_schedule', methods: ['POST'])]
    public function adminAiApplySchedule(Request $request, EntityManagerInterface $em): JsonResponse
    {
        try {
            $admin = $this->authenticate($request, $em);
            if (!$admin) {
                return $this->mobileJson(['ok' => false, 'error' => 'Неоторизиран достъп.'], 401);
            }
            if (!$this->isAdminUser($admin)) {
                return $this->mobileJson(['ok' => false, 'error' => 'Нямате администраторски права.'], 403);
            }

            $payload = json_decode($request->getContent(), true) ?? [];
            $targetMonth = (string) ($payload['targetMonth'] ?? '');
            $schedule = $payload['schedule'] ?? [];

            if (!preg_match('/^(\d{4})-(\d{2})$/', $targetMonth, $m) || !is_array($schedule)) {
                return $this->mobileJson(['ok' => false, 'error' => 'Невалидни данни за запис.'], 400);
            }

            $year = (int) $m[1];
            $month = (int) $m[2];
            $monthStart = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month));
            $monthEnd = $monthStart->modify('last day of this month')->setTime(23, 59, 59);
            $daysInMonth = (int) $monthStart->format('t');

            // 1. Изтриваме старите смени за целевия месец
            $oldShifts = $em->getRepository(Shift::class)->createQueryBuilder('s')
                ->where('s.startDate >= :start AND s.endDate <= :end')
                ->setParameter('start', $monthStart)
                ->setParameter('end', $monthEnd)
                ->getQuery()
                ->getResult();

            foreach ($oldShifts as $old) {
                $em->remove($old);
            }
            $em->flush();

            // 2. Индексираме легендите по код
            $legendsByCode = [];
            foreach ($em->getRepository(LegendItem::class)->findAll() as $l) {
                $legendsByCode[mb_strtoupper(trim($l->getCode()))] = $l;
            }

            $userRepo = $em->getRepository(User::class);
            $created = 0;

            // 3. Групираме поредните дни и записваме смените
            foreach ($schedule as $row) {
                $user = $userRepo->find((int) ($row['userId'] ?? 0));
                if (!$user || empty($row['days']) || !is_array($row['days'])) {
                    continue;
                }

                $daysByCode = [];
                foreach ($row['days'] as $dayNum => $rawVal) {
                    $dayNum = (int) $dayNum;
                    $val = trim((string) $rawVal);
                    if ($val === '' || $dayNum < 1 || $dayNum > $daysInMonth) {
                        continue;
                    }

                    $codes = preg_split('/[\/,;\s+]+/u', $val, -1, PREG_SPLIT_NO_EMPTY);
                    foreach (array_unique($codes) as $code) {
                        $codeUpper = mb_strtoupper(trim($code));
                        if (isset($legendsByCode[$codeUpper])) {
                            $daysByCode[$codeUpper][] = $dayNum;
                        }
                    }
                }

                foreach ($daysByCode as $codeUpper => $dayNums) {
                    sort($dayNums);
                    $legend = $legendsByCode[$codeUpper];
                    $rangeStart = $dayNums[0];
                    $prevDay = $dayNums[0];

                    $createShift = function (int $startD, int $endD) use ($em, $user, $legend, $year, $month, &$created) {
                        $startDate = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $startD));
                        $endDate = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $endD));
                        $daysCount = ($endD - $startD) + 1;
                        $hours = $daysCount * $legend->getDefaultDailyHours() * $legend->getMultiplier();

                        $location = match ($legend->getCode()) {
                            'КОМ' => 'Емона',
                            'О' => 'Отпуск',
                            'Б' => 'Болничен',
                            default => 'Плевен',
                        };

                        $shift = (new Shift())
                            ->setUser($user)
                            ->setLegendItem($legend)
                            ->setStartDate($startDate)
                            ->setEndDate($endDate)
                            ->setCalculatedHours($hours)
                            ->setLocation($location)
                            ->confirm();

                        $em->persist($shift);
                        $created++;
                    };

                    for ($i = 1; $i < count($dayNums); $i++) {
                        if ($dayNums[$i] === $prevDay + 1) {
                            $prevDay = $dayNums[$i];
                        } else {
                            $createShift($rangeStart, $prevDay);
                            $rangeStart = $dayNums[$i];
                            $prevDay = $dayNums[$i];
                        }
                    }
                    $createShift($rangeStart, $prevDay);
                }
            }

            $em->flush();

            return $this->mobileJson([
                'ok' => true,
                'targetMonth' => $targetMonth,
                'message' => sprintf('Графикът за %02d.%d г. е записан успешно (%d записа).', $month, $year, $created),
            ]);
        } catch (\Throwable $e) {
            return $this->mobileJson(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    private function collectMonthlyWorkloadStats(
        EntityManagerInterface $em,
        HolidayService $holidayService,
        string $rawMonth = ''
    ): array {
        if (preg_match('/^(\d{4})-(\d{2})$/', $rawMonth, $m)) {
            $year = (int) $m[1];
            $month = (int) $m[2];
        } else {
            $year = (int) date('Y');
            $month = (int) date('m');
        }

        $monthStart = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month));
        $monthEnd = $monthStart->modify('last day of this month')->setTime(23, 59, 59);

        $normData = $holidayService->getMonthlyNorm($year, $month);
        $normHours = (int) ($normData['normHours'] ?? 168);

        $allUsers = $em->getRepository(User::class)->findBy([], ['lastName' => 'ASC']);
        $regularUsers = array_values(array_filter(
            $allUsers,
            fn(User $u) => !$this->isAdminUser($u)
        ));

        $shifts = $em->getRepository(Shift::class)->createQueryBuilder('s')
            ->where('s.startDate <= :end AND s.endDate >= :start')
            ->setParameter('start', $monthStart)
            ->setParameter('end', $monthEnd)
            ->getQuery()
            ->getResult();

        $tasks = $em->getRepository(Task::class)->findAll();

        $stats = [];
        foreach ($regularUsers as $u) {
            $name = trim(($u->getMilitaryRank() ?? '') . ' ' . ($u->getFullName() ?? ''));
            $stats[$u->getId()] = [
                'userId' => $u->getId(),
                'name' => $name !== '' ? $name : (string) $u->getEmail(),
                'position' => method_exists($u, 'getPosition') ? (string) ($u->getPosition() ?? '') : '',
                'hours' => 0.0,
                'normHours' => $normHours,
                'diffFromNorm' => -$normHours,
                'tasksCompleted' => 0,
                'tasksActive' => 0,
            ];
        }

        foreach ($shifts as $s) {
            $uid = $s->getUser()?->getId();
            if ($uid && isset($stats[$uid])) {
                $stats[$uid]['hours'] += (float) $s->getCalculatedHours();
            }
        }

        foreach ($tasks as $t) {
            $uid = $t->getAssignedTo()?->getId();
            if ($uid && isset($stats[$uid])) {
                if ($t->isCompleted()) {
                    $stats[$uid]['tasksCompleted']++;
                } else {
                    $stats[$uid]['tasksActive']++;
                }
            }
        }

        foreach ($stats as &$row) {
            $row['diffFromNorm'] = $row['hours'] - $normHours;
        }
        unset($row);

        return [array_values($stats), sprintf('%02d.%d', $month, $year), $normHours];
    }
    #[Route('/tasks', name: 'api_mobile_tasks', methods: ['GET'])]
    public function tasks(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->authenticate($request, $em);
        if (!$user) {
            return $this->mobileJson(['ok' => false, 'error' => 'Неоторизиран достъп.'], 401);
        }

        $tasks = $em->getRepository(Task::class)->findBy(
            ['assignedTo' => $user],
            ['isCompleted' => 'ASC', 'dueDate' => 'ASC']
        );

        $items = [];
        foreach ($tasks as $t) {
            $items[] = [
                'id' => $t->getId(),
                'title' => $t->getTitle(),
                'description' => $t->getDescription() ?? '',
                'dueDate' => $t->getDueDate()?->format('d.m.Y H:i') ?? 'Без срок',
                'isCompleted' => $t->isCompleted(),
            ];
        }

        return $this->mobileJson(['ok' => true, 'tasks' => $items]);
    }

    #[Route('/tasks/{id}/complete', name: 'api_mobile_task_complete', methods: ['POST'])]
    public function completeTask(
        Task $task,
        Request $request,
        EntityManagerInterface $em
    ): JsonResponse {
        $user = $this->authenticate($request, $em);
        if (!$user) {
            return $this->mobileJson(['ok' => false, 'error' => 'Неоторизиран достъп.'], 401);
        }

        if ($task->getAssignedTo()?->getId() !== $user->getId()) {
            return $this->mobileJson(['ok' => false, 'error' => 'Нямате права върху тази задача.'], 403);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $note = trim((string) ($data['note'] ?? ''));

        $task->setIsCompleted(true);
        if ($note !== '' && method_exists($task, 'setCompletionNote')) {
            $task->setCompletionNote($note);
        }

        $em->flush();

        return $this->mobileJson(['ok' => true, 'message' => 'Задачата е отбелязана като изпълнена.']);
    }

    #[Route('/leaves', name: 'api_mobile_leaves_list', methods: ['GET'])]
    public function leavesList(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->authenticate($request, $em);
        if (!$user) {
            return $this->mobileJson(['ok' => false, 'error' => 'Неоторизиран достъп.'], 401);
        }

        $leaves = $em->getRepository(LeaveRequest::class)->findBy(
            ['user' => $user],
            ['id' => 'DESC']
        );

        $items = [];
        foreach ($leaves as $lv) {
            $items[] = [
                'id' => $lv->getId(),
                'type' => $lv->getType(),
                'startDate' => $lv->getStartDate()->format('Y-m-d'),
                'endDate' => $lv->getEndDate()->format('Y-m-d'),
                'status' => $lv->getStatus(),
                'reason' => $lv->getReason() ?? '',
            ];
        }

        return $this->mobileJson(['ok' => true, 'leaves' => $items]);
    }

    #[Route('/leaves', name: 'api_mobile_leaves_create', methods: ['POST'])]
    public function createLeave(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->authenticate($request, $em);
        if (!$user) {
            return $this->mobileJson(['ok' => false, 'error' => 'Неоторизиран достъп.'], 401);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $type = strtoupper(trim((string) ($data['type'] ?? 'О')));
        $startRaw = (string) ($data['startDate'] ?? '');
        $endRaw = (string) ($data['endDate'] ?? '');
        $reason = trim((string) ($data['reason'] ?? ''));

        if (!in_array($type, ['О', 'Б'], true) || $startRaw === '' || $endRaw === '') {
            return $this->mobileJson(['ok' => false, 'error' => 'Попълнете тип и дати.'], 400);
        }

        try {
            $start = new \DateTimeImmutable($startRaw);
            $end = new \DateTimeImmutable($endRaw);
        } catch (\Throwable) {
            return $this->mobileJson(['ok' => false, 'error' => 'Невалиден формат на датите.'], 400);
        }

        if ($end < $start) {
            return $this->mobileJson(['ok' => false, 'error' => 'Крайната дата не може да е преди началната.'], 400);
        }

        $today = new \DateTimeImmutable('today');
        if ($type === 'О' && $start < $today) {
            return $this->mobileJson(['ok' => false, 'error' => 'Не може да подавате отпуск със задна дата.'], 400);
        }

        if ($type === 'Б' && $start < $today->modify('-2 days')) {
            return $this->mobileJson(['ok' => false, 'error' => 'Болничен може да се подава най-много 2 дни със задна дата.'], 400);
        }

        $leave = (new LeaveRequest())
            ->setUser($user)
            ->setType($type)
            ->setStartDate($start)
            ->setEndDate($end)
            ->setReason($reason !== '' ? $reason : null)
            ->setStatus('pending');

        $em->persist($leave);
        $em->flush();

        return $this->mobileJson(['ok' => true, 'message' => 'Заявката е изпратена за одобрение.']);
    }

    // =========================================================================
    // АДМИН ЕНДПОЙНТИ
    // =========================================================================

    #[Route('/admin/overview', name: 'api_mobile_admin_overview', methods: ['GET'])]
    public function adminOverview(Request $request, EntityManagerInterface $em): JsonResponse
    {
        try {
            $admin = $this->authenticate($request, $em);
            if (!$admin) {
                return $this->mobileJson(['ok' => false, 'error' => 'Неоторизиран достъп.'], 401);
            }
            if (!$this->isAdminUser($admin)) {
                return $this->mobileJson(['ok' => false, 'error' => 'Нямате администраторски права.'], 403);
            }

            $allUsers = $em->getRepository(User::class)->findBy([], ['id' => 'ASC']);
            $users = [];
            foreach ($allUsers as $u) {
                if ($this->isAdminUser($u)) {
                    continue;
                }
                $label = trim(($u->getMilitaryRank() ?? '') . ' ' . ($u->getFullName() ?? ''));
                if ($label === '') {
                    $label = (string) $u->getEmail();
                }
                $users[] = [
                    'id' => $u->getId(),
                    'label' => $label,
                ];
            }

            $legends = [];
            foreach ($em->getRepository(LegendItem::class)->findBy([], ['code' => 'ASC']) as $l) {
                $legends[] = [
                    'id' => $l->getId(),
                    'code' => (string) $l->getCode(),
                    'name' => (string) $l->getName(),
                ];
            }

            $pending = $em->getRepository(LeaveRequest::class)->findBy(
                ['status' => 'pending'],
                ['id' => 'ASC']
            );
            $pendingLeaves = [];
            foreach ($pending as $lv) {
                $u = $lv->getUser();
                $uLabel = $u ? trim(($u->getMilitaryRank() ?? '') . ' ' . ($u->getFullName() ?? '')) : 'Служител';
                if ($uLabel === '' && $u) {
                    $uLabel = (string) $u->getEmail();
                }
                $pendingLeaves[] = [
                    'id' => $lv->getId(),
                    'userName' => $uLabel,
                    'type' => (string) $lv->getType(),
                    'startDate' => $lv->getStartDate()->format('Y-m-d'),
                    'endDate' => $lv->getEndDate()->format('Y-m-d'),
                    'reason' => (string) ($lv->getReason() ?? ''),
                ];
            }

            return $this->mobileJson([
                'ok' => true,
                'users' => $users,
                'legends' => $legends,
                'pendingLeaves' => $pendingLeaves,
            ]);
        } catch (\Throwable $e) {
            return $this->mobileJson(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/admin/workload', name: 'api_mobile_admin_workload', methods: ['GET'])]
    public function adminWorkload(
        Request $request,
        EntityManagerInterface $em,
        HolidayService $holidayService
    ): JsonResponse {
        try {
            $admin = $this->authenticate($request, $em);
            if (!$admin) {
                return $this->mobileJson(['ok' => false, 'error' => 'Неоторизиран достъп.'], 401);
            }
            if (!$this->isAdminUser($admin)) {
                return $this->mobileJson(['ok' => false, 'error' => 'Нямате администраторски права.'], 403);
            }

            $month = (string) $request->query->get('month', (new \DateTimeImmutable())->format('Y-m'));
            if (!preg_match('/^(\d{4})-(\d{2})$/', $month, $m)) {
                $month = (new \DateTimeImmutable())->format('Y-m');
                $year = (int) date('Y');
                $monthNum = (int) date('m');
            } else {
                $year = (int) $m[1];
                $monthNum = (int) $m[2];
            }

            $monthStart = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $monthNum));
            $monthEnd = $monthStart->modify('last day of this month 23:59:59');
            $daysInMonth = (int) $monthStart->format('t');

            $normData = $holidayService->getMonthlyNorm($year, $monthNum);
            $normHours = (int) ($normData['normHours'] ?? 168);
            $workingDaysCount = (int) ($normData['workingDays'] ?? round($normHours / 8));

            $isWorkingDay = [];
            $nonWorkingDays = [];
            for ($d = 1; $d <= $daysInMonth; $d++) {
                $dt = new \DateTimeImmutable(sprintf('%04d-%02d-%02d 00:00:00', $year, $monthNum, $d));
                $working = $holidayService->countWorkingDays($dt, $dt) > 0;
                $isWorkingDay[$d] = $working;
                if (!$working) {
                    $nonWorkingDays[] = $d;
                }
            }

            $legends = [];
            foreach ($em->getRepository(LegendItem::class)->findBy([], ['code' => 'ASC']) as $l) {
                $legends[] = [
                    'id' => $l->getId(),
                    'code' => (string) $l->getCode(),
                    'name' => (string) $l->getName(),
                    'color' => (string) ($l->getColorHex() ?? '#0D6EFD'),
                    'hours' => method_exists($l, 'getDefaultDailyHours') ? (float) $l->getDefaultDailyHours() : 0.0,
                    'multiplier' => method_exists($l, 'getMultiplier') ? (float) $l->getMultiplier() : 1.0,
                ];
            }

            $allUsers = $em->getRepository(User::class)->findBy([], ['lastName' => 'ASC', 'firstName' => 'ASC']);
            $shifts = $em->getRepository(Shift::class)->createQueryBuilder('s')
                ->where('s.startDate <= :end AND s.endDate >= :start')
                ->setParameter('start', $monthStart)
                ->setParameter('end', $monthEnd)
                ->orderBy('s.startDate', 'ASC')
                ->getQuery()
                ->getResult();

            $allTasks = $em->getRepository(Task::class)->findAll();

            $hoursByUser = [];
            $daysByUser = [];
            $excusedDaysByUser = [];

            foreach ($shifts as $s) {
                $uid = $s->getUser()?->getId();
                $legend = $s->getLegendItem();
                if (!$uid || !$legend) {
                    continue;
                }

                $hoursByUser[$uid] = ($hoursByUser[$uid] ?? 0.0) + (float) $s->getCalculatedHours();

                $sStart = new \DateTimeImmutable($s->getStartDate()->format('Y-m-d') . ' 00:00:00');
                $sEnd = new \DateTimeImmutable($s->getEndDate()->format('Y-m-d') . ' 00:00:00');
                $clampedStart = $sStart < $monthStart ? $monthStart : $sStart;
                $clampedEnd = $sEnd > $monthEnd ? new \DateTimeImmutable($monthEnd->format('Y-m-d') . ' 00:00:00') : $sEnd;

                $code = (string) $legend->getCode();
                $entry = [
                    'id' => $s->getId(),
                    'legendId' => $legend->getId(),
                    'code' => $code,
                    'name' => (string) $legend->getName(),
                    'color' => (string) ($legend->getColorHex() ?? '#0D6EFD'),
                    'startDate' => $s->getStartDate()->format('Y-m-d'),
                    'endDate' => $s->getEndDate()->format('Y-m-d'),
                    'hours' => (float) $s->getCalculatedHours(),
                    'location' => (string) ($s->getLocation() ?? ''),
                ];

                $cursor = $clampedStart;
                while ($cursor <= $clampedEnd) {
                    $dayNum = (int) $cursor->format('j');
                    $daysByUser[$uid][(string) $dayNum][] = $entry;

                    if (in_array($code, ['О', 'Б'], true) && !empty($isWorkingDay[$dayNum])) {
                        $excusedDaysByUser[$uid][$dayNum] = true;
                    }
                    $cursor = $cursor->modify('+1 day');
                }
            }

            $tasksTotalByUser = [];
            $tasksDoneByUser = [];
            foreach ($allTasks as $t) {
                $uid = $t->getAssignedTo()?->getId();
                if (!$uid) {
                    continue;
                }
                $tasksTotalByUser[$uid] = ($tasksTotalByUser[$uid] ?? 0) + 1;
                if ($t->isCompleted()) {
                    $tasksDoneByUser[$uid] = ($tasksDoneByUser[$uid] ?? 0) + 1;
                }
            }

            $staff = [];
            foreach ($allUsers as $u) {
                if ($this->isAdminUser($u)) {
                    continue;
                }
                $uid = $u->getId();
                $label = trim(($u->getMilitaryRank() ?? '') . ' ' . ($u->getFullName() ?? ''));
                $hrs = round($hoursByUser[$uid] ?? 0.0, 1);
                $excusedCount = isset($excusedDaysByUser[$uid]) ? count($excusedDaysByUser[$uid]) : 0;
                $effectiveNorm = max(0, $normHours - ($excusedCount * 8));

                $staff[] = [
                    'id' => $uid,
                    'label' => $label !== '' ? $label : (string) $u->getEmail(),
                    'position' => method_exists($u, 'getPosition') ? (string) ($u->getPosition() ?? '') : '',
                    'hours' => $hrs,
                    'diff' => round($hrs - $effectiveNorm, 1),
                    'tasksDone' => $tasksDoneByUser[$uid] ?? 0,
                    'tasksTotal' => $tasksTotalByUser[$uid] ?? 0,
                    'days' => (object) ($daysByUser[$uid] ?? []),
                ];
            }

            return $this->mobileJson([
                'ok' => true,
                'month' => $month,
                'year' => $year,
                'monthNum' => $monthNum,
                'daysInMonth' => $daysInMonth,
                'workingDays' => $workingDaysCount,
                'normHours' => $normHours,
                'nonWorkingDays' => $nonWorkingDays,
                'legends' => $legends,
                'staff' => $staff,
            ]);
        } catch (\Throwable $e) {
            return $this->mobileJson(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/admin/leaves/{id}/decide', name: 'api_mobile_admin_leave_decide', methods: ['POST'])]
    public function adminDecideLeave(
        LeaveRequest $leave,
        Request $request,
        EntityManagerInterface $em,
        HolidayService $holidayService
    ): JsonResponse {
        $admin = $this->authenticate($request, $em);
        if (!$admin) {
            return $this->mobileJson(['ok' => false, 'error' => 'Неоторизиран достъп.'], 401);
        }
        if (!$this->isAdminUser($admin)) {
            return $this->mobileJson(['ok' => false, 'error' => 'Нямате администраторски права.'], 403);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $action = (string) ($data['action'] ?? '');

        if (!in_array($action, ['approve', 'reject'], true)) {
            return $this->mobileJson(['ok' => false, 'error' => 'Невалидно действие.'], 400);
        }

        if ($action === 'reject') {
            $leave->setStatus('rejected');
            $em->flush();
            return $this->mobileJson(['ok' => true, 'message' => 'Заявката е отказана.']);
        }

        if ($leave->getStatus() === 'pending') {
            $legend = $em->getRepository(LegendItem::class)->findOneBy(['code' => $leave->getType()]);
            if ($legend) {
                $shift = (new Shift())
                    ->setUser($leave->getUser())
                    ->setLegendItem($legend)
                    ->setStartDate($leave->getStartDate())
                    ->setEndDate($leave->getEndDate())
                    ->setCalculatedHours(0)
                    ->setLocation($leave->getType() === 'Б' ? 'Болничен' : 'Отпуск')
                    ->confirm();

                $em->persist($shift);
            }

            if ($leave->getType() === 'О') {
                $daysToDeduct = $holidayService->countWorkingDays($leave->getStartDate(), $leave->getEndDate());

                if ($leave->getLeaveYear() !== null) {
                    $chosenBal = $em->getRepository(LeaveBalance::class)->findOneBy([
                        'user' => $leave->getUser(),
                        'year' => $leave->getLeaveYear(),
                    ]);
                    if ($chosenBal && $chosenBal->getRemainingDays() > 0) {
                        $take = min($chosenBal->getRemainingDays(), $daysToDeduct);
                        $chosenBal->setUsedDays($chosenBal->getUsedDays() + $take);
                        $daysToDeduct -= $take;
                    }
                }

                if ($daysToDeduct > 0) {
                    $balances = $em->getRepository(LeaveBalance::class)->findBy(
                        ['user' => $leave->getUser()],
                        ['year' => 'ASC']
                    );
                    foreach ($balances as $bal) {
                        if ($daysToDeduct <= 0) {
                            break;
                        }
                        $avail = $bal->getRemainingDays();
                        if ($avail > 0) {
                            $take = min($avail, $daysToDeduct);
                            $bal->setUsedDays($bal->getUsedDays() + $take);
                            $daysToDeduct -= $take;
                        }
                    }
                }
            }

            $leave->setStatus('approved');
            $em->flush();
        }

        return $this->mobileJson(['ok' => true, 'message' => 'Заявката е одобрена и добавена в графика.']);
    }

    #[Route('/admin/tasks', name: 'api_mobile_admin_tasks_list', methods: ['GET'])]
    public function adminTasksList(Request $request, EntityManagerInterface $em): JsonResponse
    {
        try {
            $admin = $this->authenticate($request, $em);
            if (!$admin) {
                return $this->mobileJson(['ok' => false, 'error' => 'Неоторизиран достъп.'], 401);
            }
            if (!$this->isAdminUser($admin)) {
                return $this->mobileJson(['ok' => false, 'error' => 'Нямате администраторски права.'], 403);
            }

            $tasks = $em->getRepository(Task::class)->findBy(
                [],
                ['isCompleted' => 'ASC', 'id' => 'DESC'],
                100
            );

            $items = [];
            foreach ($tasks as $t) {
                $u = $t->getAssignedTo();
                $uLabel = $u ? trim(($u->getMilitaryRank() ?? '') . ' ' . ($u->getFullName() ?? '')) : 'Неназначена';
                if ($uLabel === '' && $u) {
                    $uLabel = (string) $u->getEmail();
                }
                $items[] = [
                    'id' => $t->getId(),
                    'userName' => $uLabel,
                    'title' => (string) $t->getTitle(),
                    'description' => (string) ($t->getDescription() ?? ''),
                    'dueDate' => $t->getDueDate()?->format('d.m.Y H:i') ?? 'Без срок',
                    'isCompleted' => (bool) $t->isCompleted(),
                    'completionNote' => method_exists($t, 'getCompletionNote') ? (string) ($t->getCompletionNote() ?? '') : '',
                ];
            }

            return $this->mobileJson(['ok' => true, 'tasks' => $items]);
        } catch (\Throwable $e) {
            return $this->mobileJson(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/admin/tasks', name: 'api_mobile_admin_task_create', methods: ['POST'])]
    public function adminCreateTask(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $admin = $this->authenticate($request, $em);
        if (!$admin) {
            return $this->mobileJson(['ok' => false, 'error' => 'Неоторизиран достъп.'], 401);
        }
        if (!$this->isAdminUser($admin)) {
            return $this->mobileJson(['ok' => false, 'error' => 'Нямате администраторски права.'], 403);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $user = $em->getRepository(User::class)->find((int) ($data['userId'] ?? 0));
        $title = trim((string) ($data['title'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $dueDateRaw = trim((string) ($data['dueDate'] ?? ''));

        if (!$user || $this->isAdminUser($user) || $title === '') {
            return $this->mobileJson(['ok' => false, 'error' => 'Изберете служител и заглавие.'], 400);
        }

        $task = (new Task())
            ->setAssignedTo($user)
            ->setTitle($title)
            ->setDescription($description !== '' ? $description : null);

        if ($dueDateRaw !== '') {
            try {
                $task->setDueDate(new \DateTimeImmutable($dueDateRaw));
            } catch (\Throwable) {
                return $this->mobileJson(['ok' => false, 'error' => 'Невалидна дата за срок.'], 400);
            }
        }

        $em->persist($task);
        $em->flush();

        return $this->mobileJson(['ok' => true, 'message' => 'Задачата е възложена.']);
    }

    #[Route('/admin/tasks/{id}/delete', name: 'api_mobile_admin_task_delete', methods: ['POST'])]
    public function adminDeleteTask(Task $task, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $admin = $this->authenticate($request, $em);
        if (!$admin) {
            return $this->mobileJson(['ok' => false, 'error' => 'Неоторизиран достъп.'], 401);
        }
        if (!$this->isAdminUser($admin)) {
            return $this->mobileJson(['ok' => false, 'error' => 'Нямате администраторски права.'], 403);
        }

        $em->remove($task);
        $em->flush();

        return $this->mobileJson(['ok' => true, 'message' => 'Задачата е изтрита.']);
    }

    #[Route('/admin/shifts', name: 'api_mobile_admin_shift_create', methods: ['POST'])]
    public function adminCreateShift(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $admin = $this->authenticate($request, $em);
        if (!$admin) {
            return $this->mobileJson(['ok' => false, 'error' => 'Неоторизиран достъп.'], 401);
        }
        if (!$this->isAdminUser($admin)) {
            return $this->mobileJson(['ok' => false, 'error' => 'Нямате администраторски права.'], 403);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $user = $em->getRepository(User::class)->find((int) ($data['userId'] ?? 0));
        $legend = $em->getRepository(LegendItem::class)->find((int) ($data['legendId'] ?? 0));
        $startRaw = trim((string) ($data['startDate'] ?? ''));
        $endRaw = trim((string) ($data['endDate'] ?? ''));
        $location = trim((string) ($data['location'] ?? ''));
        $customHours = $data['customHours'] ?? null;

        if (!$user || !$legend || $startRaw === '' || $endRaw === '') {
            return $this->mobileJson(['ok' => false, 'error' => 'Попълнете всички задължителни полета.'], 400);
        }

        try {
            $start = new \DateTimeImmutable($startRaw);
            $end = new \DateTimeImmutable($endRaw);
        } catch (\Throwable) {
            return $this->mobileJson(['ok' => false, 'error' => 'Невалиден формат на датите.'], 400);
        }

        if ($end < $start) {
            $end = $start;
        }

        if ($customHours !== null && $customHours !== '') {
            $hours = (float) $customHours;
        } else {
            $days = (int) $start->diff($end)->days + 1;
            $hours = $days * $legend->getDefaultDailyHours() * $legend->getMultiplier();
        }

        $shift = (new Shift())
            ->setUser($user)
            ->setLegendItem($legend)
            ->setStartDate($start)
            ->setEndDate($end)
            ->setCalculatedHours($hours)
            ->setLocation($location !== '' ? $location : $legend->getName())
            ->confirm();

        $em->persist($shift);
        $em->flush();

        return $this->mobileJson(['ok' => true, 'message' => 'Смяната е записана.']);
    }
    #[Route('/admin/shifts/{id}/update', name: 'api_mobile_admin_shift_update', methods: ['POST'])]
    public function adminUpdateShift(Shift $shift, Request $request, EntityManagerInterface $em): JsonResponse
    {
        try {
            $admin = $this->authenticate($request, $em);
            if (!$admin) {
                return $this->mobileJson(['ok' => false, 'error' => 'Неоторизиран достъп.'], 401);
            }
            if (!$this->isAdminUser($admin)) {
                return $this->mobileJson(['ok' => false, 'error' => 'Нямате администраторски права.'], 403);
            }

            $data = json_decode($request->getContent(), true) ?? [];
            $legendId = (int) ($data['legendId'] ?? 0);
            $startRaw = trim((string) ($data['startDate'] ?? ''));
            $endRaw = trim((string) ($data['endDate'] ?? ''));
            $location = trim((string) ($data['location'] ?? ''));
            $customHours = $data['customHours'] ?? null;

            $legend = $legendId > 0
                ? $em->getRepository(LegendItem::class)->find($legendId)
                : $shift->getLegendItem();

            if (!$legend || $startRaw === '' || $endRaw === '') {
                return $this->mobileJson(['ok' => false, 'error' => 'Попълнете тип и дати на смяната.'], 400);
            }

            try {
                $start = new \DateTimeImmutable($startRaw);
                $end = new \DateTimeImmutable($endRaw);
            } catch (\Throwable) {
                return $this->mobileJson(['ok' => false, 'error' => 'Невалиден формат на датите.'], 400);
            }

            if ($end < $start) {
                $end = $start;
            }

            if ($customHours !== null && $customHours !== '') {
                $hours = (float) $customHours;
            } else {
                $days = (int) $start->diff($end)->days + 1;
                $hours = $days * $legend->getDefaultDailyHours() * $legend->getMultiplier();
            }

            $shift
                ->setLegendItem($legend)
                ->setStartDate($start)
                ->setEndDate($end)
                ->setCalculatedHours($hours)
                ->setLocation($location !== '' ? $location : $legend->getName());

            $em->flush();

            return $this->mobileJson(['ok' => true, 'message' => 'Смяната е обновена.']);
        } catch (\Throwable $e) {
            return $this->mobileJson(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/admin/shifts/{id}/delete', name: 'api_mobile_admin_shift_delete', methods: ['POST'])]
    public function adminDeleteShift(Shift $shift, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $admin = $this->authenticate($request, $em);
        if (!$admin) {
            return $this->mobileJson(['ok' => false, 'error' => 'Неоторизиран достъп.'], 401);
        }
        if (!$this->isAdminUser($admin)) {
            return $this->mobileJson(['ok' => false, 'error' => 'Нямате администраторски права.'], 403);
        }

        $em->remove($shift);
        $em->flush();

        return $this->mobileJson(['ok' => true, 'message' => 'Смяната е изтрита.']);
    }

    #[Route('/admin/legends', name: 'api_mobile_admin_legends_list', methods: ['GET'])]
    public function adminLegendsList(Request $request, EntityManagerInterface $em): JsonResponse
    {
        try {
            $admin = $this->authenticate($request, $em);
            if (!$admin) {
                return $this->mobileJson(['ok' => false, 'error' => 'Неоторизиран достъп.'], 401);
            }
            if (!$this->isAdminUser($admin)) {
                return $this->mobileJson(['ok' => false, 'error' => 'Нямате администраторски права.'], 403);
            }

            $items = [];
            foreach ($em->getRepository(LegendItem::class)->findBy([], ['code' => 'ASC']) as $l) {
                $items[] = [
                    'id' => $l->getId(),
                    'code' => (string) $l->getCode(),
                    'name' => (string) $l->getName(),
                    'colorHex' => (string) ($l->getColorHex() ?? '#0D6EFD'),
                    'defaultDailyHours' => (float) $l->getDefaultDailyHours(),
                    'multiplier' => (float) $l->getMultiplier(),
                ];
            }

            return $this->mobileJson(['ok' => true, 'legends' => $items]);
        } catch (\Throwable $e) {
            return $this->mobileJson(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/admin/legends', name: 'api_mobile_admin_legends_create', methods: ['POST'])]
    public function adminLegendCreate(Request $request, EntityManagerInterface $em): JsonResponse
    {
        try {
            $admin = $this->authenticate($request, $em);
            if (!$admin) {
                return $this->mobileJson(['ok' => false, 'error' => 'Неоторизиран достъп.'], 401);
            }
            if (!$this->isAdminUser($admin)) {
                return $this->mobileJson(['ok' => false, 'error' => 'Нямате администраторски права.'], 403);
            }

            $data = json_decode($request->getContent(), true) ?? [];
            $code = mb_strtoupper(trim((string) ($data['code'] ?? '')));
            $name = trim((string) ($data['name'] ?? ''));
            $color = trim((string) ($data['colorHex'] ?? '#0D6EFD'));
            $hours = (float) ($data['defaultDailyHours'] ?? 8);
            $multiplier = (float) ($data['multiplier'] ?? 1.0);

            if ($code === '' || $name === '') {
                return $this->mobileJson(['ok' => false, 'error' => 'Въведете код и наименование.'], 400);
            }
            if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
                $color = '#0D6EFD';
            }

            $legend = new LegendItem();
            $legend->setCode($code);
            $legend->setName($name);
            $legend->setColorHex($color);
            $legend->setDefaultDailyHours($hours);
            $legend->setMultiplier($multiplier > 0 ? $multiplier : 1.0);

            $em->persist($legend);
            $em->flush();

            return $this->mobileJson(['ok' => true, 'message' => 'Типът смяна е добавен.'], 201);
        } catch (\Throwable $e) {
            return $this->mobileJson(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/admin/legends/{id}/update', name: 'api_mobile_admin_legends_update', methods: ['POST'])]
    public function adminLegendUpdate(LegendItem $legend, Request $request, EntityManagerInterface $em): JsonResponse
    {
        try {
            $admin = $this->authenticate($request, $em);
            if (!$admin) {
                return $this->mobileJson(['ok' => false, 'error' => 'Неоторизиран достъп.'], 401);
            }
            if (!$this->isAdminUser($admin)) {
                return $this->mobileJson(['ok' => false, 'error' => 'Нямате администраторски права.'], 403);
            }

            $data = json_decode($request->getContent(), true) ?? [];
            $code = mb_strtoupper(trim((string) ($data['code'] ?? $legend->getCode())));
            $name = trim((string) ($data['name'] ?? $legend->getName()));
            $color = trim((string) ($data['colorHex'] ?? $legend->getColorHex()));
            $hours = isset($data['defaultDailyHours']) ? (float) $data['defaultDailyHours'] : $legend->getDefaultDailyHours();
            $multiplier = isset($data['multiplier']) ? (float) $data['multiplier'] : $legend->getMultiplier();

            if ($code === '' || $name === '') {
                return $this->mobileJson(['ok' => false, 'error' => 'Въведете код и наименование.'], 400);
            }
            if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
                $color = '#0D6EFD';
            }

            $legend->setCode($code);
            $legend->setName($name);
            $legend->setColorHex($color);
            $legend->setDefaultDailyHours($hours);
            $legend->setMultiplier($multiplier > 0 ? $multiplier : 1.0);

            $em->flush();

            return $this->mobileJson(['ok' => true, 'message' => 'Типът смяна е обновен.']);
        } catch (\Throwable $e) {
            return $this->mobileJson(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/admin/legends/{id}/delete', name: 'api_mobile_admin_legends_delete', methods: ['POST'])]
    public function adminLegendDelete(LegendItem $legend, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $admin = $this->authenticate($request, $em);
        if (!$admin) {
            return $this->mobileJson(['ok' => false, 'error' => 'Неоторизиран достъп.'], 401);
        }
        if (!$this->isAdminUser($admin)) {
            return $this->mobileJson(['ok' => false, 'error' => 'Нямате администраторски права.'], 403);
        }

        try {
            $em->remove($legend);
            $em->flush();
            return $this->mobileJson(['ok' => true, 'message' => 'Типът смяна е изтрит.']);
        } catch (\Throwable) {
            return $this->mobileJson(['ok' => false, 'error' => 'Този тип смяна се използва в графика и не може да бъде изтрит.'], 409);
        }
    }

    #[Route('/admin/users', name: 'api_mobile_admin_users_list', methods: ['GET'])]
    public function adminUsersList(Request $request, EntityManagerInterface $em): JsonResponse
    {
        try {
            $admin = $this->authenticate($request, $em);
            if (!$admin) {
                return $this->mobileJson(['ok' => false, 'error' => 'Неоторизиран достъп.'], 401);
            }
            if (!$this->isAdminUser($admin)) {
                return $this->mobileJson(['ok' => false, 'error' => 'Нямате администраторски права.'], 403);
            }

            $allUsers = $em->getRepository(User::class)->findBy([], ['id' => 'ASC']);
            $items = [];
            foreach ($allUsers as $u) {
                $label = trim(($u->getMilitaryRank() ?? '') . ' ' . ($u->getFullName() ?? ''));
                $items[] = [
                    'id' => $u->getId(),
                    'email' => (string) $u->getEmail(),
                    'label' => $label !== '' ? $label : '(без име)',
                    'position' => method_exists($u, 'getPosition') ? (string) ($u->getPosition() ?? '') : '',
                    'isAdmin' => $this->isAdminUser($u),
                    'isApproved' => method_exists($u, 'isApproved') ? (bool) $u->isApproved() : true,
                    'isSelf' => $u->getId() === $admin->getId(),
                ];
            }

            usort($items, static fn (array $a, array $b) => ((int) $a['isApproved'] <=> (int) $b['isApproved']) ?: ($a['id'] <=> $b['id']));

            return $this->mobileJson(['ok' => true, 'users' => $items]);
        } catch (\Throwable $e) {
            return $this->mobileJson(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/admin/users/{id}/approve', name: 'api_mobile_admin_user_approve', methods: ['POST'])]
    public function adminApproveUser(User $user, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $admin = $this->authenticate($request, $em);
        if (!$admin) {
            return $this->mobileJson(['ok' => false, 'error' => 'Неоторизиран достъп.'], 401);
        }
        if (!$this->isAdminUser($admin)) {
            return $this->mobileJson(['ok' => false, 'error' => 'Нямате администраторски права.'], 403);
        }

        if (method_exists($user, 'setIsApproved')) {
            $user->setIsApproved(true);
            $em->flush();
        }

        return $this->mobileJson(['ok' => true, 'message' => 'Потребителят е одобрен.']);
    }

    #[Route('/admin/users/{id}/toggle-role', name: 'api_mobile_admin_user_toggle_role', methods: ['POST'])]
    public function adminToggleUserRole(User $user, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $admin = $this->authenticate($request, $em);
        if (!$admin) {
            return $this->mobileJson(['ok' => false, 'error' => 'Неоторизиран достъп.'], 401);
        }
        if (!$this->isAdminUser($admin)) {
            return $this->mobileJson(['ok' => false, 'error' => 'Нямате администраторски права.'], 403);
        }
        if ($user->getId() === $admin->getId()) {
            return $this->mobileJson(['ok' => false, 'error' => 'Не може да сменяте собствената си роля.'], 400);
        }

        if ($this->isAdminUser($user)) {
            $user->setRoles(['ROLE_USER']);
            $msg = 'Правата са сменени на Служител.';
        } else {
            $user->setRoles(['ROLE_ADMIN']);
            $msg = 'Правата са сменени на Администратор.';
        }

        $em->flush();

        return $this->mobileJson(['ok' => true, 'message' => $msg]);
    }

    #[Route('/admin/users/{id}/delete', name: 'api_mobile_admin_user_delete', methods: ['POST'])]
    public function adminDeleteUser(User $user, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $admin = $this->authenticate($request, $em);
        if (!$admin) {
            return $this->mobileJson(['ok' => false, 'error' => 'Неоторизиран достъп.'], 401);
        }
        if (!$this->isAdminUser($admin)) {
            return $this->mobileJson(['ok' => false, 'error' => 'Нямате администраторски права.'], 403);
        }
        if ($user->getId() === $admin->getId()) {
            return $this->mobileJson(['ok' => false, 'error' => 'Не може да изтриете собствения си профил.'], 400);
        }

        try {
            $em->remove($user);
            $em->flush();
            return $this->mobileJson(['ok' => true, 'message' => 'Потребителят е изтрит.']);
        } catch (\Throwable) {
            return $this->mobileJson(['ok' => false, 'error' => 'Потребителят има свързани смени/задачи и не може да бъде изтрит.'], 409);
        }
    }

    // =========================================================================
    // ПОМОЩНИ МЕТОДИ
    // =========================================================================

    private function mobileJson(array $data, int $status = 200): JsonResponse
    {
        $response = $this->json($data, $status);
        $response->headers->set('Content-Length', (string) strlen((string) $response->getContent()));
        return $response;
    }

    private function authenticate(Request $request, EntityManagerInterface $em): ?User
    {
        $authHeader = (string) $request->headers->get('Authorization', '');
        if (!preg_match('/^Bearer\s+(\S+)$/i', $authHeader, $m)) {
            return null;
        }

        $token = trim($m[1]);
        if ($token === '') {
            return null;
        }

        return $em->getRepository(User::class)->findOneBy(['apiToken' => $token]);
    }

    private function isAdminUser(User $user): bool
    {
        $roles = $user->getRoles();
        return in_array('ROLE_ADMIN', $roles, true) || in_array('ROLE_SUPER_ADMIN', $roles, true);
    }

    private function serializeUser(User $user): array
    {
        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'firstName' => method_exists($user, 'getFirstName') ? (string) ($user->getFirstName() ?? '') : '',
            'lastName' => method_exists($user, 'getLastName') ? (string) ($user->getLastName() ?? '') : '',
            'fullName' => method_exists($user, 'getFullName') ? (string) ($user->getFullName() ?? $user->getEmail()) : (string) $user->getEmail(),
            'militaryRank' => method_exists($user, 'getMilitaryRank') ? (string) ($user->getMilitaryRank() ?? '') : '',
            'position' => method_exists($user, 'getPosition') ? (string) ($user->getPosition() ?? '') : '',
            'isAdmin' => $this->isAdminUser($user),
        ];
    }
    #[Route('/admin/report/export', name: 'api_mobile_admin_report_export', methods: ['GET'])]
    public function adminExportExcel(
        Request $request,
        EntityManagerInterface $em,
        HolidayService $holidayService
    ): Response {
        try {
            $admin =$this->authenticate($request,$em);
            if (!$admin) {
                return $this->mobileJson(['ok' => false, 'error' => 'Неоторизиран достъп.'], 401);
            }
            if (!$this->isAdminUser($admin)) {
                return $this->mobileJson(['ok' => false, 'error' => 'Нямате администраторски права.'], 403);
            }

            $month = (string)$request->query->get('month', (new \DateTimeImmutable())->format('Y-m'));
            if (!preg_match('/^(\d{4})-(\d{2})$/',$month, $m)) {$month = (new \DateTimeImmutable())->format('Y-m');
                $year = (int) date('Y');$monthNum = (int) date('m');
            } else {
                $year = (int)$m[1];
                $monthNum = (int)$m[2];
            }

            $monthStart = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year,$monthNum));
            $monthEnd =$monthStart->modify('last day of this month 23:59:59');
            $daysInMonth = (int)$monthStart->format('t');
            $normData =$holidayService->getMonthlyNorm($year,$monthNum);
            $normHours = (int) ($normData['normHours'] ?? 168);

            $isWorkingDay = [];
            for ($d = 1; $d <=$daysInMonth; $d++) {$dt = new \DateTimeImmutable(sprintf('%04d-%02d-%02d 00:00:00', $year,$monthNum, $d));$isWorkingDay[$d] =$holidayService->countWorkingDays($dt,$dt) > 0;
            }

            $allUsers =$em->getRepository(User::class)->findBy([], ['lastName' => 'ASC', 'firstName' => 'ASC']);
            $shifts =$em->getRepository(Shift::class)->createQueryBuilder('s')
                ->where('s.startDate <= :end AND s.endDate >= :start')
                ->setParameter('start', $monthStart)
                ->setParameter('end', $monthEnd)
                ->orderBy('s.startDate', 'ASC')
                ->getQuery()
                ->getResult();

            $hoursByUser = [];
            $daysByUser = [];$excusedByUser = [];

            foreach ($shifts as$s) {
                $uid =$s->getUser()?->getId();
                $legend =$s->getLegendItem();
                if (!$uid || !$legend) { continue; }$hoursByUser[$uid] = ($hoursByUser[$uid] ?? 0.0) + (float)$s->getCalculatedHours();

                $sStart = new \DateTimeImmutable($s->getStartDate()->format('Y-m-d') . ' 00:00:00');$sEnd = new \DateTimeImmutable($s->getEndDate()->format('Y-m-d') . ' 00:00:00');$clampedStart = $sStart <$monthStart ? $monthStart :$sStart;
                $clampedEnd = $sEnd >$monthEnd ? new \DateTimeImmutable($monthEnd->format('Y-m-d') . ' 00:00:00') :$sEnd;

                $code = (string)$legend->getCode();
                $cursor =$clampedStart;
                while ($cursor <=$clampedEnd) {
                    $dayNum = (int)$cursor->format('j');
                    $daysByUser[$uid][$dayNum][] =$code;
                    if (in_array($code, ['О', 'Б'], true) && !empty($isWorkingDay[$dayNum])) {$excusedByUser[$uid][$dayNum] = true;
                    }
                    $cursor =$cursor->modify('+1 day');
                }
            }

            $spreadsheet = new Spreadsheet();
            $sheet =$spreadsheet->getActiveSheet();
            $sheet->setTitle($month);

            $sheet->setCellValue([1, 1], 'Звание, Име и Фамилия');
            for ($d = 1; $d <=$daysInMonth; $d++) {$sheet->setCellValue([$d + 1, 1],$d);
            }
            $sheet->setCellValue([$daysInMonth + 2, 1], 'Часове');
            $sheet->setCellValue([$daysInMonth + 3, 1], 'До норма');

            $rowNum = 2;
            foreach ($allUsers as$u) {
                if ($this->isAdminUser($u)) { continue; }$uid = $u->getId();$label = trim(($u->getMilitaryRank() ?? '') . ' ' . ($u->getFullName() ?? ''));
                if ($label === '') {
                    $label = (string)$u->getEmail();
                }

                $sheet->setCellValue([1, $rowNum],$label);
                for ($d = 1; $d <= $daysInMonth; $d++) {
                    if (!empty($daysByUser[$uid][$d])) {
                        $sheet->setCellValue([$d + 1, $rowNum], implode('/',$daysByUser[$uid][$d]));
                    }
                }

                $hrs = round($hoursByUser[$uid] ?? 0.0, 1);$excused = isset($excusedByUser[$uid]) ? count($excusedByUser[$uid]) : 0;
                $diff = round($hrs - max(0, $normHours - ($excused * 8)), 1);

                $sheet->setCellValue([$daysInMonth + 2,$rowNum], $hrs);$sheet->setCellValue([$daysInMonth + 3,$rowNum], $diff);$rowNum++;
            }

            $tmpFile = tempnam(sys_get_temp_dir(), 'div_xlsx_');
            (new Xlsx($spreadsheet))->save($tmpFile);
            $bytes = (string) file_get_contents($tmpFile);
            @unlink($tmpFile);

            return new Response($bytes, 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="grafik_' . $month . '.xlsx"',
                'Content-Length' => (string) strlen($bytes),
            ]);
        } catch (\Throwable $e) {
            return $this->mobileJson(['ok' => false, 'error' =>$e->getMessage()], 500);
        }
    }

    #[Route('/admin/report/import', name: 'api_mobile_admin_report_import', methods: ['POST'])]
    public function adminImportExcel(Request $request, EntityManagerInterface$em): JsonResponse
    {
        try {
            $admin =$this->authenticate($request,$em);
            if (!$admin) {
                return $this->mobileJson(['ok' => false, 'error' => 'Неоторизиран достъп.'], 401);
            }
            if (!$this->isAdminUser($admin)) {
                return $this->mobileJson(['ok' => false, 'error' => 'Нямате администраторски права.'], 403);
            }

            $file =$request->files->get('file');
            if (!$file) {
                return $this->mobileJson(['ok' => false, 'error' => 'Не е избран Excel файл.'], 400);
            }

            $month = (string)$request->request->get('month', (new \DateTimeImmutable())->format('Y-m'));
            if (!preg_match('/^(\d{4})-(\d{2})$/', $month,$m)) {
                return $this->mobileJson(['ok' => false, 'error' => 'Невалиден месец (YYYY-MM).'], 400);
            }
            $year = (int)$m[1];
            $monthNum = (int)$m[2];
            $replace = in_array((string)$request->request->get('replace', '1'), ['1', 'true', 'on'], true);

            $monthStart = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year,$monthNum));
            $monthEnd =$monthStart->modify('last day of this month 23:59:59');
            $daysInMonth = (int)$monthStart->format('t');

            $spreadsheet = IOFactory::load($file->getPathname());
            $rows =$spreadsheet->getActiveSheet()->toArray(null, true, true, false);

            // Намираме реда със заглавията (където са дните 1, 2, 3...)
            $headerIdx = -1;
            $dayColMap = [];
            foreach ($rows as $rIdx =>$row) {
                if ($rIdx > 8) { break; }$foundDays = [];
                foreach ($row as $cIdx =>$val) {
                    $txt = trim((string)$val);
                    if (ctype_digit($txt)) {
                        $dNum = (int)$txt;
                        if ($dNum >= 1 &&$dNum <= $daysInMonth) {$foundDays[$dNum] =$cIdx;
                        }
                    }
                }
                if (count($foundDays) >= 15) {
                    $headerIdx =$rIdx;
                    $dayColMap =$foundDays;
                    break;
                }
            }

            if ($headerIdx === -1) {
                return $this->mobileJson(['ok' => false, 'error' => 'Неразпознат формат на Excel файла (липсват колони с дни 1–31).'], 400);
            }

            $userMap = [];
            foreach ($em->getRepository(User::class)->findAll() as$u) {
                if ($this->isAdminUser($u)) { continue; }$rankName = mb_strtolower(trim(($u->getMilitaryRank() ?? '') . ' ' . ($u->getFullName() ?? '')));
                $onlyName = mb_strtolower(trim((string) ($u->getFullName() ?? '')));
                $email = mb_strtolower(trim((string)$u->getEmail()));
                if ($rankName !== '') {$userMap[$rankName] =$u;
                }
                if ($onlyName !== '') {$userMap[$onlyName] =$u;
                }
                if ($email !== '') {$userMap[$email] =$u;
                }
            }

            $legendMap = [];
            foreach ($em->getRepository(LegendItem::class)->findAll() as $l) {$legendMap[mb_strtoupper(trim((string) $l->getCode()))] =$l;
            }

            if ($replace) {
                $existing =$em->getRepository(Shift::class)->createQueryBuilder('s')
                    ->where('s.startDate <= :end AND s.endDate >= :start')
                    ->setParameter('start', $monthStart)
                    ->setParameter('end', $monthEnd)
                    ->getQuery()
                    ->getResult();
                foreach ($existing as$old) {
                    $em->remove($old);
                }
                $em->flush();
            }

            $created = 0;
            $matchedUsers = 0;

            for ($i =$headerIdx + 1; $i < count($rows); $i++) {$row = $rows[$i];
                $rawName = trim((string) ($row[0] ?? ''));
                if ($rawName === '') {
                    continue;
                }

                // Взимаме само първия ред на клетката, ако отдолу има длъжност
                $firstLine = trim(preg_split('/\R/u',$rawName)[0] ?? $rawName);$key = mb_strtolower(preg_replace('/\s+/u', ' ', $firstLine));$user = $userMap[$key] ?? null;

                if (!$user) {
                    foreach ($userMap as $uKey =>$uObj) {
                        if ($uKey !== '' && (str_contains($key,$uKey) || str_contains($uKey,$key))) {
                            $user =$uObj;
                            break;
                        }
                    }
                }
                if (!$user) { continue; }$matchedUsers++;

                foreach ($dayColMap as$dayNum => $colIdx) {$cellVal = trim((string) ($row[$colIdx] ?? ''));
                    if ($cellVal === '') {
                        continue;
                    }
                    $tokens = preg_split('/[\s,\/;\vert{}]+/u', mb_strtoupper($cellVal), -1, PREG_SPLIT_NO_EMPTY);
                    $dateObj = new \DateTimeImmutable(sprintf('\%04d-\%02d-\%02d 00:00:00',$year, $monthNum,$dayNum));

                    foreach ($tokens as$code) {
                        if (!isset($legendMap[$code])) {
                            continue;
                        }
                        $legend =$legendMap[$code];$hours = $legend->getDefaultDailyHours() *$legend->getMultiplier();

                        $shift = (new Shift())
                            ->setUser($user)
                            ->setLegendItem($legend)
                            ->setStartDate($dateObj)
                            ->setEndDate($dateObj)
                            ->setCalculatedHours($hours)
                            ->setLocation($legend->getName())
                            ->confirm();

                        $em->persist($shift);$created++;
                    }
                }
            }

            $em->flush();

            return $this->mobileJson([
                'ok' => true,
                'message' => sprintf('Импортирани %d смени за %d служители.', $created,$matchedUsers),
            ]);
        } catch (\Throwable $e) {
            return $this->mobileJson(['ok' => false, 'error' =>$e->getMessage()], 500);
        }
    }
}

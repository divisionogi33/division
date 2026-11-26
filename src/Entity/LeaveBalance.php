<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'leave_balances')]
#[ORM\UniqueConstraint(name: 'uniq_user_year', columns: ['user_id', 'year'])]
class LeaveBalance
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column]
    private int $year;

    #[ORM\Column]
    private int $totalDays = 30;

    #[ORM\Column]
    private int $usedDays = 0;

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function setUser(User $user): static {$this->user = $user; return$this; }
    public function getYear(): int { return $this->year; }
    public function setYear(int $year): static {$this->year = $year; return$this; }
    public function getTotalDays(): int { return $this->totalDays; }
    public function setTotalDays(int $totalDays): static {$this->totalDays = $totalDays; return$this; }
    public function getUsedDays(): int { return $this->usedDays; }
    public function setUsedDays(int $usedDays): static {$this->usedDays = $usedDays; return$this; }
    public function getRemainingDays(): int { return max(0, $this->totalDays -$this->usedDays); }
}

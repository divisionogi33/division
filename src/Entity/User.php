<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity]
#[ORM\Table(name: 'users')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    #[ORM\Column(type: 'json')]
    private array $roles = [];

    #[ORM\Column(nullable: true)]
    private ?string $password = null;

    #[ORM\Column(length: 255, nullable: true, unique: true)]
    private ?string $googleId = null;

    #[ORM\Column(length: 100)]
    private string $firstName = '';

    #[ORM\Column(length: 100)]
    private string $lastName = '';

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $militaryRank = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $position = null;

    #[ORM\Column]
    private bool $isApproved = false;

    #[ORM\OneToMany(targetEntity: Shift::class, mappedBy: 'user', orphanRemoval: true)]
    private Collection $shifts;

    #[ORM\OneToMany(targetEntity: Task::class, mappedBy: 'assignedTo', orphanRemoval: true)]
    private Collection $tasks;
    #[ORM\Column(length: 64, unique: true, nullable: true)]
    private ?string $apiToken = null;

    public function getShifts(): Collection
    {
        return $this->shifts;
    }

    public function setShifts(Collection $shifts): void
    {
        $this->shifts = $shifts;
    }

    public function getTasks(): Collection
    {
        return $this->tasks;
    }

    public function setTasks(Collection $tasks): void
    {
        $this->tasks = $tasks;
    }

    public function getApiToken(): ?string
    {
        return $this->apiToken;
    }

    public function setApiToken(?string $apiToken): void
    {
        $this->apiToken = $apiToken;
    }

    public function __construct()
    {
        $this->shifts = new ArrayCollection();
        $this->tasks = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): static { $this->email = $email; return $this; }

    public function getUserIdentifier(): string { return $this->email; }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';
        return array_unique($roles);
    }
    public function setRoles(array $roles): static { $this->roles = $roles; return $this; }

    public function getPassword(): ?string { return $this->password; }
    public function setPassword(?string $password): static { $this->password = $password; return $this; }
    public function eraseCredentials(): void {}

    public function getGoogleId(): ?string { return $this->googleId; }
    public function setGoogleId(?string $googleId): static { $this->googleId = $googleId; return $this; }

    public function getFullName(): string { return trim($this->firstName . ' ' . $this->lastName); }
    public function getFirstName(): string { return $this->firstName; }
    public function setFirstName(string $firstName): static { $this->firstName = $firstName; return $this; }
    public function getLastName(): string { return $this->lastName; }
    public function setLastName(string $lastName): static { $this->lastName = $lastName; return $this; }

    public function getMilitaryRank(): ?string { return $this->militaryRank; }
    public function setMilitaryRank(?string $militaryRank): static { $this->militaryRank = $militaryRank; return $this; }

    public function getPosition(): ?string { return $this->position; }
    public function setPosition(?string $position): static { $this->position = $position; return $this; }

    public function isApproved(): bool { return $this->isApproved; }
    public function setIsApproved(bool $isApproved): static { $this->isApproved = $isApproved; return $this; }

    public function isProfileComplete(): bool
    {
        return !empty($this->firstName) && !empty($this->lastName)
            && !empty($this->militaryRank) && !empty($this->position);
    }
}

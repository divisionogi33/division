<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'tasks')]
class Task
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'tasks')]
    #[ORM\JoinColumn(nullable: false)]
    private User $assignedTo;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Shift $shift = null;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $dueDate = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private bool $isCompleted = false;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $completionNote = null;
    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }
    public function getCompletionNote(): ?string { return $this->completionNote; }
    public function setCompletionNote(?string $completionNote): static { $this->completionNote = $completionNote; return $this; }
    public function getId(): ?int { return $this->id; }
    public function getAssignedTo(): User { return $this->assignedTo; }
    public function setAssignedTo(User $assignedTo): static { $this->assignedTo = $assignedTo; return $this; }
    public function getShift(): ?Shift { return $this->shift; }
    public function setShift(?Shift $shift): static { $this->shift = $shift; return $this; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): static { $this->title = $title; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }
    public function getDueDate(): ?\DateTimeImmutable { return $this->dueDate; }
    public function setDueDate(?\DateTimeImmutable $dueDate): static { $this->dueDate = $dueDate; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function isCompleted(): bool { return $this->isCompleted; }

    public function toggleCompleted(): static
    {
        $this->isCompleted = !$this->isCompleted;
        $this->completedAt = $this->isCompleted ? new \DateTimeImmutable() : null;
        return $this;
    }
    public function setIsCompleted(bool $isCompleted): static
    {
        $this->isCompleted = $isCompleted;

        return $this;
    }

    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }
}

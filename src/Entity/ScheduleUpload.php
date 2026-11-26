<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'schedule_uploads')]
class ScheduleUpload
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $uploadedBy;

    #[ORM\Column(length: 255)]
    private string $filePath;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $parsedAiData = null; // Суровият JSON от Gemini за преглед

    #[ORM\Column(length: 30)]
    private string $status = 'pending_review'; // pending_review | confirmed | rejected

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUploadedBy(): User { return $this->uploadedBy; }
    public function setUploadedBy(User $uploadedBy): static { $this->uploadedBy = $uploadedBy; return $this; }
    public function getFilePath(): string { return $this->filePath; }
    public function setFilePath(string $filePath): static { $this->filePath = $filePath; return $this; }
    public function getParsedAiData(): ?array { return $this->parsedAiData; }
    public function setParsedAiData(?array $parsedAiData): static { $this->parsedAiData = $parsedAiData; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}

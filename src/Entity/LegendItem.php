<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'legend_items')]
class LegendItem
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, unique: true)]
    private string $code; // напр. "ЕМ", "РС", "НД", "О"

    #[ORM\Column(length: 100)]
    private string $name; // напр. "Емона", "Работна смяна"

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2)]
    private string $defaultDailyHours = '8.00'; // Колко часа носи на ден

    #[ORM\Column(type: 'decimal', precision: 4, scale: 3)]
    private string $multiplier = '1.000'; // Коефициент (напр. 1.143 за нощен труд)

    #[ORM\Column(length: 7)]
    private string $colorHex = '#3788d8'; // Цвят за FullCalendar

    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function setCode(string $code): static { $this->code = strtoupper($code); return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }
    public function getDefaultDailyHours(): float { return (float) $this->defaultDailyHours; }
    public function setDefaultDailyHours(float $hours): static { $this->defaultDailyHours = number_format($hours, 2, '.', ''); return $this; }
    public function getMultiplier(): float { return (float) $this->multiplier; }
    public function setMultiplier(float $multiplier): static { $this->multiplier = number_format($multiplier, 3, '.', ''); return $this; }
    public function getColorHex(): string { return $this->colorHex; }
    public function setColorHex(string $colorHex): static { $this->colorHex = $colorHex; return $this; }
}

<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'fullmetrix_config')]
class FullmetrixConfig
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 64)]
    private string $configKey;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $value = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $key)
    {
        $this->configKey = $key;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getKey(): string
    {
        return $this->configKey;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function setValue(?string $value): self
    {
        $this->value = $value;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}

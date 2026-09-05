<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'fullmetrix_log')]
#[ORM\Index(name: 'idx_fmtx_log_fingerprint', columns: ['fingerprint', 'created_at'])]
#[ORM\Index(name: 'idx_fmtx_log_created', columns: ['created_at'])]
class FullmetrixLog
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue]
    /** Doctrine assigns the identifier by reflection, PHPStan cannot see it. */
    /** @phpstan-ignore property.unusedType */
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 32)]
    private string $type;

    #[ORM\Column(type: 'string', length: 256)]
    private string $message;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $details = null;

    #[ORM\Column(type: 'string', length: 32)]
    private string $fingerprint;

    #[ORM\Column(type: 'integer')]
    private int $count = 1;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $type, string $message, ?array $details, string $fingerprint)
    {
        $this->type = $type;
        $this->message = $message;
        $this->details = $details;
        $this->fingerprint = $fingerprint;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getDetails(): ?array
    {
        return $this->details;
    }

    public function getFingerprint(): string
    {
        return $this->fingerprint;
    }

    public function getCount(): int
    {
        return $this->count;
    }

    public function bumpCount(): void
    {
        ++$this->count;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

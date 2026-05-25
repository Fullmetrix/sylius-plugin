<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Fullmetrix\SyliusPlugin\Entity\FullmetrixLog;
use Fullmetrix\SyliusPlugin\Service\ConfigStore;
use Fullmetrix\SyliusPlugin\Service\Logger;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

final class DashboardController
{
    public function __construct(
        private readonly ConfigStore $config,
        private readonly Logger $logger,
        private readonly EntityManagerInterface $em,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function show(Request $request): Response
    {
        $tab = (string) $request->query->get('tab', 'connection');

        return new Response($this->twig->render('@Fullmetrix/admin/dashboard.html.twig', [
            'tab' => $tab,
            'registered' => $this->config->isRegistered(),
            'connection_code' => $this->config->getConnectionCode(),
            'last_sync' => $this->config->get(ConfigStore::KEY_LAST_SYNC),
            'sync_in_progress' => $this->config->get(ConfigStore::KEY_SYNC_IN_PROGRESS),
            'export_count' => (int) $this->config->get(ConfigStore::KEY_EXPORT_COUNT, 0),
            'webhooks_enabled' => true === $this->config->get(ConfigStore::KEY_WEBHOOKS_ENABLED, true),
            'logs' => $this->loadLogs(),
        ]));
    }

    public function clearLogs(): RedirectResponse
    {
        $this->logger->clear();

        return new RedirectResponse(
            $this->urls->generate('fullmetrix_admin_connection', ['tab' => 'logs']),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadLogs(): array
    {
        $rows = $this->em->getRepository(FullmetrixLog::class)
            ->createQueryBuilder('l')
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults(30)
            ->getQuery()
            ->getResult();

        $logs = [];
        foreach ($rows as $row) {
            if (!$row instanceof FullmetrixLog) {
                continue;
            }
            $logs[] = [
                'type' => $row->getType(),
                'message' => $row->getMessage(),
                'details' => $row->getDetails(),
                'count' => $row->getCount(),
                'created_at' => $row->getCreatedAt()->format('Y-m-d H:i:s'),
            ];
        }

        return $logs;
    }
}

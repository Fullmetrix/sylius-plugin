<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Fullmetrix\SyliusPlugin\Entity\FullmetrixLog;
use Fullmetrix\SyliusPlugin\Service\ConfigStore;
use Fullmetrix\SyliusPlugin\Service\ConnectionManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

final class ConnectionController
{
    public function __construct(
        private readonly ConfigStore $config,
        private readonly ConnectionManager $connection,
        private readonly EntityManagerInterface $em,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function index(Request $request): Response
    {
        $error = null;
        $submittedCode = null;

        if ($request->isMethod('POST') && !$this->config->isRegistered()) {
            $code = (string) $request->request->get('connection_code', '');
            $siteUrl = $request->getSchemeAndHttpHost();
            $result = $this->connection->connect($code, $siteUrl);

            if ($result['success']) {
                return new RedirectResponse(
                    $this->urls->generate('fullmetrix_admin_connection'),
                );
            }

            $error = $result['error'] ?? 'unknown_error';
            $submittedCode = $code;
        }

        $tab = (string) $request->query->get('tab', 'connection');

        $template = $this->twig->getLoader()->exists('@SyliusAdmin/shared/layout/base.html.twig')
            ? '@FullmetrixPlugin/admin/dashboard.v2.html.twig'
            : '@FullmetrixPlugin/admin/dashboard.html.twig';

        return new Response($this->twig->render($template, [
            'tab' => $tab,
            'registered' => $this->config->isRegistered(),
            'connection_code' => $this->config->getConnectionCode(),
            'error' => $error,
            'submitted_code' => $submittedCode,
            'last_sync' => $this->config->get(ConfigStore::KEY_LAST_SYNC),
            'sync_in_progress' => $this->config->get(ConfigStore::KEY_SYNC_IN_PROGRESS),
            'export_count' => (int) $this->config->get(ConfigStore::KEY_EXPORT_COUNT, 0),
            'webhooks_enabled' => true === $this->config->get(ConfigStore::KEY_WEBHOOKS_ENABLED, true),
            'logs' => $this->loadLogs(),
        ]));
    }

    public function disconnect(): RedirectResponse
    {
        $this->connection->disconnect();

        return new RedirectResponse(
            $this->urls->generate('fullmetrix_admin_connection'),
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

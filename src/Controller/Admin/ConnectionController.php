<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\Controller\Admin;

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
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function index(Request $request): Response
    {
        if ($request->isMethod('POST') && !$this->config->isRegistered()) {
            $code = (string) $request->request->get('connection_code', '');
            $siteUrl = $request->getSchemeAndHttpHost();
            $result = $this->connection->connect($code, $siteUrl);

            if (!$result['success']) {
                return new Response(
                    $this->twig->render('@Fullmetrix/admin/connection.html.twig', [
                        'registered' => false,
                        'error' => $result['error'] ?? 'unknown_error',
                        'submitted_code' => $code,
                    ]),
                );
            }

            return new RedirectResponse(
                $this->urls->generate('fullmetrix_admin_connection'),
            );
        }

        return new Response(
            $this->twig->render('@Fullmetrix/admin/connection.html.twig', [
                'registered' => $this->config->isRegistered(),
                'connection_code' => $this->config->getConnectionCode(),
                'error' => null,
            ]),
        );
    }

    public function disconnect(): RedirectResponse
    {
        $this->connection->disconnect();

        return new RedirectResponse(
            $this->urls->generate('fullmetrix_admin_connection'),
        );
    }
}

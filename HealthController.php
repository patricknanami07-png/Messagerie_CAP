<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Point de contrôle pour Render (healthCheckPath).
 * Volontairement sans accès base de données : un compute Neon en veille
 * ferait échouer le contrôle et provoquerait des redémarrages inutiles.
 */
final class HealthController extends AbstractController
{
    #[Route('/health', name: 'app_health', methods: ['GET', 'HEAD'])]
    public function health(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok'], 200, ['Cache-Control' => 'no-store']);
    }
}

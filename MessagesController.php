<?php

namespace App\Controller;

use App\Entity\Messages;
use App\Entity\Users;
use App\Form\MessagesType;
use App\Repository\MessagesRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[IsGranted('ROLE_USER')]
final class MessagesController extends AbstractController
{
    /**
     * Nombre de messages non lus (utilisé par la cloche, mise à jour automatique).
     */
    #[Route('/messages/unread-count', name: 'app_unread_count', methods: ['GET'])]
    public function unreadCount(MessagesRepository $repo): JsonResponse
    {
        /** @var Users $user */
        $user = $this->getUser();
        $count = $repo->countReceived($user, false);

        return new JsonResponse(
            ['count' => $count],
            200,
            ['Cache-Control' => 'no-store']
        );
    }

    #[Route('/messages', name: 'app_messages')]
    public function index(Request $request, MessagesRepository $repo): Response
    {
        /** @var Users $user */
        $user = $this->getUser();
        $perPage = 5;

        $tab = 'sent' === $request->query->get('tab', 'received') ? 'sent' : 'received';

        $receivedCount = $repo->countReceived($user);
        $sentCount = $repo->countSent($user);
        $unreadCount = $repo->countReceived($user, false);

        $listCount = 'sent' === $tab ? $sentCount : $receivedCount;
        $totalPages = max(1, (int) ceil($listCount / $perPage));
        $page = min(max(1, $request->query->getInt('page', 1)), $totalPages);
        $pageItems = 'sent' === $tab
            ? $repo->findSentPage($user, $page, $perPage)
            : $repo->findReceivedPage($user, null, $page, $perPage);

        // Activité des 7 derniers jours (messages reçus + envoyés), une seule requête.
        $byDay = $repo->countByDay($user, new \DateTimeImmutable('today -6 days'));
        $activity = [];
        for ($i = 6; $i >= 0; --$i) {
            $day = (new \DateTimeImmutable('today'))->modify('-' . $i . ' days')->format('Y-m-d');
            $activity[$day] = (int) ($byDay[$day] ?? 0);
        }

        return $this->render('messages/index.html.twig', [
            'controller_name' => 'MessagesController',
            'perPage' => $perPage,
            'tab' => $tab,
            'page' => $page,
            'totalPages' => $totalPages,
            'pageItems' => $pageItems,
            'receivedCount' => $receivedCount,
            'sentCount' => $sentCount,
            'unreadCount' => $unreadCount,
            'listCount' => $listCount,
            'activity' => $activity,
        ]);
    }

    #[Route('/send', name: 'app_send')]
    public function send(
        Request $request,
        EntityManagerInterface $em,
        MessagesRepository $repo,
        SluggerInterface $slugger,
        #[Autowire('%kernel.project_dir%/var/uploads')] string $uploadsDir,
    ): Response {
        $message = new Messages();
        $form = $this->createForm(MessagesType::class, $message);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $message->setSender($this->getUser());
            $message->setCreatedAt(new \DateTimeImmutable());
            $message->setIsRead(false);

            /** @var UploadedFile|null $attachment */
            $attachment = $form->get('attachment')->getData();
            if ($attachment) {
                $original = $attachment->getClientOriginalName();
                $name = $slugger->slug(pathinfo($original, PATHINFO_FILENAME))
                    . '-' . uniqid() . '.' . ($attachment->guessExtension() ?: 'bin');
                $attachment->move($uploadsDir, $name);
                $message->setAttachmentFilename($name);
                $message->setAttachmentOriginalName($original);
            }

            /** @var UploadedFile|null $voice */
            $voice = $form->get('voice')->getData();
            if ($voice) {
                $name = 'voice-' . uniqid() . '.' . ($voice->guessExtension() ?: 'webm');
                $voice->move($uploadsDir, $name);
                $message->setVoiceFilename($name);
            }

            $em->persist($message);
            $em->flush();

            $this->addFlash('success', 'Message envoyé avec succès.');

            return $this->redirectToRoute('app_messages');
        }

        /** @var Users $currentUser */
        $currentUser = $this->getUser();

        return $this->render('messages/send.html.twig', [
            'form' => $form,
            'unreadCount' => $repo->countReceived($currentUser, false),
        ]);
    }

    #[Route('/received', name: 'app_received')]
    public function received(Request $request, MessagesRepository $repo): Response
    {
        /** @var Users $user */
        $user = $this->getUser();
        $perPage = 5;

        $filterParam = $request->query->get('filter', 'all');
        $currentFilter = in_array($filterParam, ['unread', 'read'], true) ? $filterParam : 'all';
        $isRead = match ($currentFilter) {
            'unread' => false,
            'read' => true,
            default => null,
        };

        $receivedCount = $repo->countReceived($user);
        $unreadCount = $repo->countReceived($user, false);
        $readCount = $receivedCount - $unreadCount;

        $listCount = match ($currentFilter) {
            'unread' => $unreadCount,
            'read' => $readCount,
            default => $receivedCount,
        };
        $totalPages = max(1, (int) ceil($listCount / $perPage));
        $page = min(max(1, $request->query->getInt('page', 1)), $totalPages);

        return $this->render('messages/received.html.twig', [
            'perPage' => $perPage,
            'currentFilter' => $currentFilter,
            'page' => $page,
            'totalPages' => $totalPages,
            'pageItems' => $repo->findReceivedPage($user, $isRead, $page, $perPage),
            'receivedCount' => $receivedCount,
            'unreadCount' => $unreadCount,
            'readCount' => $readCount,
            'listCount' => $listCount,
        ]);
    }

    #[Route('/sent', name: 'app_sent')]
    public function sent(MessagesRepository $repo): Response
    {
        /** @var Users $user */
        $user = $this->getUser();

        // La page filtre et recherche côté navigateur sur la liste affichée :
        // on charge donc les 200 messages les plus récents (au lieu de tous).
        $sentLimit = 200;

        return $this->render('messages/sent.html.twig', [
            'sentMessages' => $repo->findSentPage($user, 1, $sentLimit),
            'totalSent' => $repo->countSent($user),
            'readCount' => $repo->countSent($user, true),
            'sentLimit' => $sentLimit,
        ]);
    }

    #[Route('/read/{id}', name: 'app_read', requirements: ['id' => '\d+'])]
    public function read(Messages $message, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();

        // Seuls l'expéditeur et le destinataire peuvent consulter le message
        if ($message->getSender() !== $user && $message->getRecipient() !== $user) {
            throw $this->createAccessDeniedException();
        }

        // Marquer comme lu uniquement si c'est le destinataire qui ouvre
        if ($message->getRecipient() === $user && !$message->isRead()) {
            $message->setIsRead(true);
            $em->flush();
        }

        return $this->render('messages/read.html.twig', [
            'message' => $message,
        ]);
    }

    #[Route('/file/{id}/{type}', name: 'app_file', requirements: ['id' => '\d+', 'type' => 'attachment|voice'])]
    public function download(
        Request $request,
        Messages $message,
        string $type,
        #[Autowire('%kernel.project_dir%/var/uploads')] string $uploadsDir,
    ): Response {
        $user = $this->getUser();
        if ($message->getSender() !== $user && $message->getRecipient() !== $user) {
            throw $this->createAccessDeniedException();
        }

        $filename = $type === 'voice' ? $message->getVoiceFilename() : $message->getAttachmentFilename();

        if (!$filename) {
            throw $this->createNotFoundException();
        }

        $path = $uploadsDir . '/' . $filename;

        if (!is_file($path)) {
            throw $this->createNotFoundException();
        }

        // ?dl=1 => télécharger ; sinon => afficher dans le navigateur
        $forceDownload = $type === 'attachment' && $request->query->getBoolean('dl');

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(
            $forceDownload ? ResponseHeaderBag::DISPOSITION_ATTACHMENT : ResponseHeaderBag::DISPOSITION_INLINE,
            $type === 'voice' ? $filename : ($message->getAttachmentOriginalName() ?? $filename)
        );
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    #[Route('/delete/{id}', name: 'app_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(
        int $id,
        Request $request,
        MessagesRepository $messagesRepository,
        EntityManagerInterface $em
    ): Response {
        $user = $this->getUser();

        if (!$user) {
            throw $this->createAccessDeniedException('Vous devez être connecté.');
        }

        $message = $messagesRepository->find($id);

        if (!$message) {
            $this->addFlash('error', 'Ce message n’existe plus ou a déjà été supprimé.');
            return $this->redirectToRoute('app_received');
        }

        // Seuls l'expéditeur et le destinataire peuvent supprimer
        if ($message->getSender() !== $user && $message->getRecipient() !== $user) {
            throw $this->createAccessDeniedException('Vous ne pouvez pas supprimer ce message.');
        }

        // Vérification du token CSRF
        if (!$this->isCsrfTokenValid('delete' . $message->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $em->remove($message);
        $em->flush();

        $this->addFlash('success', 'Message supprimé avec succès.');

        return $this->redirectToRoute('app_received');
    }
}
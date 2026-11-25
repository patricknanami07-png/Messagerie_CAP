<?php

namespace App\Repository;

use App\Entity\Messages;
use App\Entity\Users;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Messages>
 */
class MessagesRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Messages::class);
    }

    /**
     * Nombre de messages reçus par l'utilisateur (null = tous, true = lus, false = non lus).
     */
    public function countReceived(Users $user, ?bool $isRead = null): int
    {
        return $this->countFor('recipient', $user, $isRead);
    }

    /**
     * Nombre de messages envoyés par l'utilisateur (null = tous, true = lus, false = non lus).
     */
    public function countSent(Users $user, ?bool $isRead = null): int
    {
        return $this->countFor('sender', $user, $isRead);
    }

    /**
     * Une page de messages reçus, du plus récent au plus ancien.
     *
     * @return Messages[]
     */
    public function findReceivedPage(Users $user, ?bool $isRead, int $page, int $perPage): array
    {
        return $this->pageFor('recipient', $user, $isRead, $page, $perPage);
    }

    /**
     * Une page de messages envoyés, du plus récent au plus ancien.
     *
     * @return Messages[]
     */
    public function findSentPage(Users $user, int $page, int $perPage): array
    {
        return $this->pageFor('sender', $user, null, $page, $perPage);
    }

    /**
     * Nombre de messages (reçus + envoyés) par jour depuis $since.
     * Retourne un tableau « AAAA-MM-JJ » => nombre.
     *
     * @return array<string, int|string>
     */
    public function countByDay(Users $user, \DateTimeImmutable $since): array
    {
        $sql = 'SELECT d, COUNT(*) FROM ('
            . " SELECT to_char(created_at, 'YYYY-MM-DD') AS d FROM messages WHERE recipient_id = :recipient AND created_at >= :since_a"
            . ' UNION ALL'
            . " SELECT to_char(created_at, 'YYYY-MM-DD') AS d FROM messages WHERE sender_id = :sender AND created_at >= :since_b"
            . ') t GROUP BY d';

        return $this->getEntityManager()->getConnection()->fetchAllKeyValue($sql, [
            'recipient' => $user->getId(),
            'sender' => $user->getId(),
            'since_a' => $since->format('Y-m-d H:i:s'),
            'since_b' => $since->format('Y-m-d H:i:s'),
        ]);
    }

    private function countFor(string $side, Users $user, ?bool $isRead): int
    {
        $qb = $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.' . $side . ' = :user')
            ->setParameter('user', $user);

        $this->applyReadFilter($qb, $isRead);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @return Messages[]
     */
    private function pageFor(string $side, Users $user, ?bool $isRead, int $page, int $perPage): array
    {
        $qb = $this->createQueryBuilder('m')
            // Charge expéditeur et destinataire dans la même requête (évite une requête par message).
            ->addSelect('s', 'r')
            ->innerJoin('m.sender', 's')
            ->innerJoin('m.recipient', 'r')
            ->andWhere('m.' . $side . ' = :user')
            ->setParameter('user', $user)
            ->orderBy('m.created_at', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->setFirstResult(max(0, $page - 1) * $perPage)
            ->setMaxResults($perPage);

        $this->applyReadFilter($qb, $isRead);

        return $qb->getQuery()->getResult();
    }

    private function applyReadFilter(QueryBuilder $qb, ?bool $isRead): void
    {
        if (null !== $isRead) {
            $qb->andWhere('m.is_read = :isRead')->setParameter('isRead', $isRead);
        }
    }
}

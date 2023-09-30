<?php

namespace Conveyor\Persistence\Interfaces;

interface UserAssocPersistenceInterface extends GenericPersistenceInterface
{
    /**
     * Associate a user id to a fd.
     *
     * @param int $fd
     * @param int $userId
     * @return void
     */
    public function assoc(int $fd, int $userId): void;

    /**
     * Disassociate a user from a userId.
     *
     * @param int $userId
     * @return void
     */
    public function disassoc(int $userId): void;

    /**
     * Get user-id for a fd.
     *
     * @param int $fd
     * @return ?int
     */
    public function getAssoc(int $fd): ?int;

    /**
     * Retrieve all associations.
     *
     * @return array Format:
     */
    public function getAllAssocs(): array;
}

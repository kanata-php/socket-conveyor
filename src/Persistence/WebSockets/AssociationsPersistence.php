<?php

namespace Conveyor\Persistence\WebSockets;

use Conveyor\Models\WsAssociation;
use Conveyor\Persistence\Abstracts\GenericPersistence;
use Conveyor\Persistence\DatabaseBootstrap;
use Conveyor\Persistence\Interfaces\UserAssocPersistenceInterface;
use Error;
use Exception;

class AssociationsPersistence extends GenericPersistence implements UserAssocPersistenceInterface
{
    /**
     * Associate a user id to a fd.
     *
     * @param int $fd
     * @param int $userId
     * @return void
     */
    public function assoc(int $fd, int $userId): void
    {
        $this->disassoc($userId);
        try {
            WsAssociation::create([
                'fd' => $fd,
                'user_id' => $userId,
            ]);
        } catch (Exception | Error $e) {
            // --
        }
    }

    /**
     * Disassociate a user from a userId.
     *
     * @param int $userId
     * @return void
     */
    public function disassoc(int $userId): void
    {
        try {
            WsAssociation::where('user_id', '=', $userId)?->delete();
        } catch (Exception | Error $e) {
            // --
        }
    }

    /**
     * Get user-id for a fd.
     *
     * @param int $fd
     * @return ?int
     */
    public function getAssoc(int $fd): ?int
    {
        return WsAssociation::where('fd', $fd)->get()->first()?->user_id;
    }

    /**
     * Retrieve all associations.
     *
     * @return array<int, array<array-key, int>> Format: [fd => userId][]
     */
    public function getAllAssocs(): array
    {
        try {
            $associations = WsAssociation::all()->toArray();
        } catch (Exception | Error $e) {
            return [];
        }

        if (empty($associations)) {
            return [];
        }

        $connections = [];
        foreach ($associations as $association) {
            $connections[$association['fd']] = $association['user_id'];
        }

        return $connections;
    }

    /**
     * @throws Exception
     */
    public function refresh(bool $fresh = false): static
    {
        (new DatabaseBootstrap($this->databaseOptions, $this->manager))->migrateAssociationPersistence($fresh);

        if (!$fresh) {
            return $this;
        }

        WsAssociation::truncate();
        return $this;
    }
}

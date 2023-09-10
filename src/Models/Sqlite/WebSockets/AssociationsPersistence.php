<?php

namespace Conveyor\Models\Sqlite\WebSockets;

use Conveyor\Models\Interfaces\UserAssocPersistenceInterface;
use Conveyor\Models\Sqlite\DatabaseBootstrap;
use Conveyor\Models\Sqlite\WsAssociation;
use Error;
use Exception;

class AssociationsPersistence implements UserAssocPersistenceInterface
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
        } catch (Exception|Error $e) {
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
        } catch (Exception|Error $e) {
            // --
        }
    }

    /**
     * Get user-id for a fd.
     *
     * @param ?int $fd
     * @return int
     */
    public function getAssoc(int $fd): ?int
    {
        return WsAssociation::where('fd', '=', $fd)->get()->first()?->user_id;
    }

    /**
     * Retrieve all associations.
     *
     * @return array Format:
     */
    public function getAllAssocs(): array
    {
        try {
            $associations = WsAssociation::all()->toArray();
        } catch (Exception|Error $e) {
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

    public function refresh(bool $fresh = false, ?string $databasePath = null): static
    {
        (new DatabaseBootstrap($databasePath))->migrateAssociationPersistence($fresh);

        if (!$fresh) {
            return $this;
        }

        WsAssociation::truncate();
        return $this;
    }
}

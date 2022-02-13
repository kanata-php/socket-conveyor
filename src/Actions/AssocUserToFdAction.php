<?php

namespace Econoroute\Actions;

use Conveyor\Actions\Abstractions\AbstractAction;
use Econoroute\Models\FdTeamAssociation;
use Error;
use Exception;
use InvalidArgumentException;

class AssocUserToFdAction extends AbstractAction
{
    /** @var string */
    protected $name = 'assoc-user-to-fd-action';

    /**
     * @param array $data
     *
     * @return array
     *
     * @throws InvalidArgumentException
     * @throws Exception
     */
    public function execute(array $data): mixed
    {
        $this->connectTeamToFd($data);
        return null;
    }

    public function connectTeamToFd(array $data): void
    {
        if (null === $this->fd) {
            throw new Exception('FD not specified!');
        }

        // We need to clean any reference to the given FD in case it still exist.
        try {
            $association = FdTeamAssociation::getInstance()->where('fd', '=', $this->fd);
            $association->delete();
        } catch (Exception|Error $e) {
            // --
        }

        // Associate the current team to that FD.
        try {
            FdTeamAssociation::getInstance()->createRecord([
                'fd' => $this->fd,
                'team_id' => $data['params']['content']['teamId'],
            ]);
        } catch (Exception|Error $e) {
            // --
        }
    }

    /**
     * @param array $data
     * @return void
     *
     * @throws InvalidArgumentException
     */
    public function validateData(array $data) : void
    {
        if (!isset($data['params']['content']['teamId'])) {
            throw new InvalidArgumentException('The teamID is required to associate connection to team!');
        }
    }
}

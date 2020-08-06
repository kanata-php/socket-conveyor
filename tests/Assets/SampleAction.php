<?php

namespace Tests\Assets;

use Conveyor\Actions\Abstractions\AbstractAction;
use Conveyor\Actions\Traits\ProcedureActionTrait;

class SampleAction extends  AbstractAction
{
    use ProcedureActionTrait;

    /** @var string */
    protected $name = 'sample-action';

    /**
     * @return bool
     *
     * @throws Exception
     */
    public function execute()
    {
        return true;
    }

    /**
     * @param array $data
     * @return void
     *
     * @throws InvalidArgumentException
     */
    public function validateData(array $data) : void
    {
        if (!isset($data['action'])) {
            throw new InvalidArgumentException('SampleAction required \'action\' field to be created!');
        }
    }
}
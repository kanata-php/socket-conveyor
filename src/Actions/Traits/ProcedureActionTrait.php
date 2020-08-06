<?php

namespace Conveyor\Actions\Traits;

/**
 * Action's Trait for Controller similar procedures.
 */

trait ProcedureActionTrait
{
    /**
     * @param array $data
     */
    public function __invoke(array $data)
    {
        $this->validateData($data);

        $this->data = $data;

        return $this->execute();
    }
}

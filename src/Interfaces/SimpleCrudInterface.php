<?php

namespace Conveyor\Interfaces;

interface SimpleCrudInterface
{
    public function create(array $data);
    public function update(int $id, array $data);
    public function get($id);
    public function delete(int $id);
}

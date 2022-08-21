<?php

namespace Tests;

class SocketAssocTest extends SocketHandlerTestCase
{
    public array $userKeys = [];

    public function testCanExecuteUserAssocAction()
    {
        $userId = 10;

        $this->assertTrue($this->userAssocPersistence->getAssoc(1) === null);

        $this->assocUser(1, $userId);

        $this->assertTrue($this->userAssocPersistence->getAssoc(1) === $userId);
    }
}

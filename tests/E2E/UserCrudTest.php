<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\LibrarianNetworkBrowserTest;

final class UserCrudTest extends LibrarianNetworkBrowserTest
{
    private const string PASSWORD = 'Synthetic-browser-password-05';
    private const string CHANGED_PASSWORD = 'Changed-synthetic-password-05';

    public function testCreateEditPasswordAndDeleteUser(): void
    {
        $this->login();
        $suffix = bin2hex(random_bytes(6));
        $email = 'created-'.$suffix.'@example.test';
        $editedEmail = 'renamed-'.$suffix.'@example.test';
        $page = $this->visit('/admin/users/new');
        $page->getByLabel('Email', ['exact' => true])->fill($email);
        $page->getByLabel('Password', ['exact' => true])->fill(self::PASSWORD);
        $page->getByLabel('Admin', ['exact' => true])->check();
        $this->submitEasyAdminForm();
        $page = $this->visit('/admin/users?query='.rawurlencode($email));
        $this->assertPageContains($email);
        $userRow = $page->locator('tbody tr')->filter(['hasText' => $email]);
        $userUrl = $userRow->locator('a.action-edit')->getAttribute('href');
        $userDetailUrl = $userRow->locator('a.action-detail')->getAttribute('href');
        self::assertNotNull($userUrl);
        self::assertNotNull($userDetailUrl);
        try {
            $page->goto($userUrl);
            $page->getByLabel('Email', ['exact' => true])->fill($editedEmail);
            $page->getByLabel('Password', ['exact' => true])->fill(self::CHANGED_PASSWORD);
            $this->submitEasyAdminForm();
            $page->goto($userUrl);
            self::assertSame($editedEmail, $page->getByLabel('Email', ['exact' => true])->inputValue());
            $this->logout();
            $this->login($editedEmail, self::CHANGED_PASSWORD);
            self::assertTrue($page->getByRole('heading', ['name' => 'MCP Tools Dashboard', 'exact' => true])->isVisible());
        } finally {
            $this->visit('/logout');
            $this->login();
            $this->deleteRecord($userDetailUrl);
        }
        $page = $this->visit('/admin/users?query='.rawurlencode($editedEmail));
        self::assertStringNotContainsString($editedEmail, $page->locator('tbody')->textContent());
    }
}

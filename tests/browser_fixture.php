<?php
/** Local demo fixture. Capture stdout to a private temporary file, never to logs. */
declare(strict_types=1);
define('OC_CONSOLE', true);
require '/var/www/html/lib/base.php';
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, (string)$error); exit(1); });
$users = \OCP\Server::get(\OCP\IUserManager::class);
$session = \OCP\Server::get(\OCP\IUserSession::class);
$files = \OCP\Server::get(\OCP\Files\IRootFolder::class);
$manager = \OCP\Server::get(\OCP\Share\IManager::class);
$suffix = bin2hex(random_bytes(3));
$password = bin2hex(random_bytes(24));
$people = [];
foreach (range('A', 'F') as $letter) {
    $people[$letter] = $users->createUser($letter . '_demo_' . $suffix, $letter === 'A' ? $password : bin2hex(random_bytes(24)));
    $people[$letter]->setDisplayName('Benutzer ' . $letter);
}
$session->setUser($people['A']);
$home = $files->getUserFolder($people['A']->getUID());
$root = $home->newFolder('Projekt');
$sub1 = $root->newFolder('subordner');
$sub1->newFolder('Archiv');
$sub2 = $root->newFolder('subordner2');
foreach ([['A', $root, 'B', 17], ['B', $root, 'C', 17], ['C', $sub1, 'D', 1], ['C', $sub1, 'E', 1], ['C', $sub2, 'F', 1]] as [$by, $source, $to, $permissions]) {
    $session->setUser($people[$by]);
    $folder = $files->getUserFolder($people[$by]->getUID());
    $folder->getDirectoryListing();
    $node = $folder->getFirstNodeById($source->getId());
    $share = $manager->newShare()->setNode($node)->setShareType(0)->setSharedBy($people[$by]->getUID())
        ->setSharedWith($people[$to]->getUID())->setPermissions($permissions);
    $manager->createShare($share);
}
\OCP\Server::get(\OCP\IConfig::class)->setUserValue($people['A']->getUID(), 'firstrunwizard', 'show', '99.0.0');
echo json_encode(['uid' => $people['A']->getUID(), 'password' => $password, 'rootId' => $root->getId(),
    'people' => array_map(static fn ($user) => $user->getUID(), $people)], JSON_THROW_ON_ERROR);

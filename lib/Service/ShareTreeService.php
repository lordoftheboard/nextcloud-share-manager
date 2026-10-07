<?php
declare(strict_types=1);

namespace OCA\ShareManager\Service;

use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Share\IManager;
use OCP\Share\IShare;

/** Owner-scoped share inventory; no direct database access or global user enumeration. */
class ShareTreeService {
    private const MAX_NODES = 5000;
    public function __construct(
        private IManager $manager,
        private IRootFolder $rootFolder,
        private IGroupManager $groups,
        private IUserManager $users,
    ) {}

    /** @return array<string, IShare> */
    public function shares(string $uid): array {
        $result = [];
        $types = array_unique(array_values(array_filter(
            (new \ReflectionClass(IShare::class))->getConstants(),
            static fn ($value, $key) => str_starts_with($key, 'TYPE_') && $value !== 2,
            ARRAY_FILTER_USE_BOTH,
        )));
        foreach ($types as $type) {
            if (!$this->manager->shareProviderExists($type)) continue;
            foreach ($this->manager->getSharesBy($uid, $type, null, true, -1, 0) as $share) {
                if ($share->getShareOwner() === $uid) $result[$share->getFullId()] = $share;
            }
        }
        ksort($result);
        return $result;
    }

    public function serialize(IShare $share, string $uid): array {
        $node = $share->getNode();
        $home = $this->rootFolder->getUserFolder($uid);
        $members = [];
        if ($share->getShareType() === IShare::TYPE_USER) {
            $members = [$share->getSharedWith()];
        } elseif ($share->getShareType() === IShare::TYPE_GROUP) {
            $group = $this->groups->get($share->getSharedWith());
            if ($group === null) throw new \RuntimeException('Eine Freigabe verweist auf eine unbekannte Gruppe.');
            foreach ($group->getUsers() as $user) $members[] = $user->getUID();
        }
        sort($members, SORT_STRING);
        return [
            'id' => $share->getFullId(), 'nodeId' => $node->getId(),
            'name' => $node->getName(), 'path' => $home->getRelativePath($node->getPath()),
            'type' => $share->getShareType(), 'recipient' => $share->getSharedWith(),
            'grantedBy' => $share->getSharedBy(), 'owner' => $share->getShareOwner(),
            'permissions' => $share->getPermissions(),
            'expiration' => $share->getExpirationDate()?->format('Y-m-d'),
            'passwordProtected' => $share->getPassword() !== null && $share->getPassword() !== '',
            'members' => $members, 'foreign' => $share->getSharedBy() !== $uid,
        ];
    }

    private function within(string $path, string $root): bool {
        return $path === $root || str_starts_with($path, rtrim($root, '/') . '/');
    }

    private function owned(Node $node, string $uid): bool {
        return $node->getOwner()?->getUID() === $uid
            && !($node->getMountPoint() instanceof \OCP\Files\Mount\IShareOwnerlessMount);
    }

    public function ownedNode(string $uid, int $id): Node {
        $node = $this->rootFolder->getUserFolder($uid)->getFirstNodeById($id);
        if ($node === null || !$this->owned($node, $uid)) {
            throw new \DomainException('Nur der ursprüngliche Eigentümer darf diesen Baum verwalten.');
        }
        return $node;
    }

    /** Include all folders, plus files with explicit shares. Incomplete inventories fail closed. */
    private function nodes(Node $root, string $uid, array $sharedIds): array {
        $result = [];
        $stack = [[$root, null]];
        while ($stack !== []) {
            [$node, $parentId] = array_pop($stack);
            if (!$this->owned($node, $uid)) {
                throw new \DomainException('Der Baum enthält fremde oder eigentümerlose Einbindungen. Rechteentzug ist hier nicht vollständig prüfbar.');
            }
            if (isset($result[$node->getId()])) throw new \RuntimeException('Mehrdeutige Ordner-Einbindung im Baum.');
            $result[$node->getId()] = ['node' => $node, 'parentId' => $parentId];
            if (count($result) > self::MAX_NODES) throw new \LengthException('Der Baum überschreitet 5000 Ordner/Freigabestellen. Keine unvollständige Auswertung durchgeführt.');
            if ($node instanceof Folder) {
                $children = $node->getDirectoryListing();
                usort($children, static fn (Node $a, Node $b) => strnatcasecmp($b->getName(), $a->getName()));
                foreach ($children as $child) {
                    if ($child instanceof Folder || isset($sharedIds[$child->getId()])) $stack[] = [$child, $node->getId()];
                }
            }
        }
        return $result;
    }

    public function inventory(string $uid): array {
        $home = $this->rootFolder->getUserFolder($uid);
        $rows = [];
        $shareNodes = [];
        foreach ($this->shares($uid) as $share) {
            // getNode resolves in the resource owner's namespace, not the resharer's mount path.
            $node = $share->getNode();
            if (!$this->owned($node, $uid) || $home->getRelativePath($node->getPath()) === null) {
                throw new \DomainException('Mindestens eine Freigabe liegt in einer fremden oder eigentümerlosen Einbindung. Vollständige Eigentümersicht ist hier nicht unterstützt.');
            }
            $rows[] = $this->serialize($share, $uid);
            $shareNodes[$node->getId()] = $node;
        }
        uasort($shareNodes, static fn (Node $a, Node $b) => strcmp($a->getPath(), $b->getPath()));
        $roots = [];
        foreach ($shareNodes as $node) {
            foreach ($roots as $root) {
                if ($this->within($node->getPath(), $root->getPath())) continue 2;
            }
            $roots[] = $node;
        }
        $trees = [];
        foreach ($roots as $root) {
            $rootPath = $home->getRelativePath($root->getPath());
            $rootShares = array_values(array_filter($rows, static fn ($row) => $row['nodeId'] === $root->getId() && !$row['foreign']));
            $nodes = $this->nodes($root, $uid, $shareNodes);
            $treeRows = array_values(array_filter($rows, fn ($row) => $this->within($row['path'], $rootPath)));
            $allUsers = [];
            $outputNodes = [];
            foreach ($nodes as $id => $entry) {
                $node = $entry['node'];
                $path = $home->getRelativePath($node->getPath());
                $explicit = [];
                $inherited = [];
                $effective = [];
                foreach ($treeRows as $row) {
                    if (!$this->within($path, $row['path'])) continue;
                    foreach ($row['members'] as $member) {
                        if ($member === $uid) continue;
                        $effective[$member] = ($effective[$member] ?? 0) | $row['permissions'];
                    }
                    if ($row['nodeId'] === $id) {
                        $row['belowTop'] = $id !== $root->getId();
                        $row['deviation'] = !$this->matchesBaseline($row, $rootShares);
                        $row['canRevoke'] = true;
                        $explicit[] = $row;
                    } else {
                        $inherited[] = $row;
                    }
                }
                foreach ($effective as $member => $permissions) $allUsers[$member] = $this->userLabel($member);
                $outputNodes[] = [
                    'id' => $id, 'parentId' => $entry['parentId'], 'name' => $node->getName(),
                    'path' => $path, 'isFolder' => $node instanceof Folder,
                    'shares' => $explicit, 'inherited' => $inherited, 'effectiveUsers' => $effective,
                ];
            }
            ksort($allUsers);
            $trees[] = ['id' => $root->getId(), 'name' => $root->getName(), 'path' => $rootPath,
                'users' => array_map(static fn ($id, $label) => ['id' => $id, 'label' => $label], array_keys($allUsers), $allUsers),
                'nodes' => $outputNodes];
        }
        return ['currentUser' => $uid, 'trees' => $trees];
    }

    private function matchesBaseline(array $row, array $baseline): bool {
        foreach ($baseline as $reference) {
            $match = true;
            foreach (['type', 'recipient', 'permissions', 'expiration', 'passwordProtected'] as $key) {
                if ($row[$key] !== $reference[$key]) $match = false;
            }
            if ($match) return true;
        }
        return false;
    }

    private function userLabel(string $uid): string {
        return $this->users->get($uid)?->getDisplayName() ?? $uid;
    }

    public function plan(string $uid, int $rootId, string $targetUser, bool $includeSharedAccess): array {
        $this->ownedNode($uid, $rootId);
        if ($targetUser === $uid || !$this->users->userExists($targetUser)) {
            throw new \InvalidArgumentException('Ein vorhandener Benutzer außer dem Eigentümer muss gewählt werden.');
        }
        $inventory = $this->inventory($uid);
        $tree = null;
        foreach ($inventory['trees'] as $candidate) if ($candidate['id'] === $rootId) $tree = $candidate;
        if ($tree === null) throw new \DomainException('Wähle die oberste Freigabeebene des Baums; geerbter Zugriff darf nicht außerhalb des gewählten Bereichs verbleiben.');
        $affected = [];
        $blockers = [];
        $collateral = [];
        $fingerprint = [];
        foreach ($tree['nodes'] as $entry) {
            foreach ($entry['shares'] as $share) {
                $fingerprint[] = $share;
                $type = $share['type'];
                if (!in_array($type, [IShare::TYPE_USER, IShare::TYPE_GROUP, IShare::TYPE_LINK], true)) {
                    $blockers[] = ['reason' => 'unsupported', 'share' => $share,
                        'message' => 'Weitere Freigabeart im Baum: vollständiger Entzug nicht unterstützt.'];
                    continue;
                }
                if ($type === IShare::TYPE_USER && $share['recipient'] === $targetUser) $affected[] = $share;
                if (($type === IShare::TYPE_GROUP && in_array($targetUser, $share['members'], true)) || $type === IShare::TYPE_LINK) {
                    $collateral[] = $share;
                    if ($includeSharedAccess) $affected[] = $share;
                    else $blockers[] = ['reason' => 'sharedAccess', 'share' => $share,
                        'message' => $type === IShare::TYPE_GROUP ? 'Benutzer hat Zugriff über eine Gruppe.' : 'Öffentlicher Link kann weiterhin Zugriff ermöglichen.'];
                }
            }
        }
        usort($affected, static fn ($a, $b) => strlen($b['path']) <=> strlen($a['path']) ?: strcmp($a['id'], $b['id']));
        $plan = ['rootId' => $rootId, 'rootPath' => $tree['path'], 'targetUser' => $targetUser,
            'targetLabel' => $this->userLabel($targetUser), 'includeSharedAccess' => $includeSharedAccess,
            'affected' => $affected, 'collateral' => $collateral, 'blockers' => $blockers,
            'canExecute' => $blockers === [] && $affected !== []];
        // Bind confirmation to the current actor, complete inventory and group memberships.
        $plan['fingerprint'] = hash('sha256', json_encode([$uid, $plan, $fingerprint], JSON_THROW_ON_ERROR));
        return $plan;
    }

    /** Verify currently effective access, including provider access lists and ancestor grants. */
    public function remaining(string $uid, int $rootId, string $targetUser): array {
        $root = $this->ownedNode($uid, $rootId);
        $nodes = [$root->getId() => $root];
        foreach ($this->shares($uid) as $share) {
            $node = $share->getNode();
            if ($this->within($node->getPath(), $root->getPath())) $nodes[$node->getId()] = $node;
        }
        $remaining = [];
        foreach ($nodes as $node) {
            $access = $this->manager->getAccessList($node, true, true);
            if (array_key_exists($targetUser, $access['users'] ?? []) || ($access['public'] ?? false)
                || !empty($access['remote']) || !empty($access['mail'])) {
                $remaining[] = $this->rootFolder->getUserFolder($uid)->getRelativePath($node->getPath());
            }
        }
        return array_values(array_unique($remaining));
    }
}

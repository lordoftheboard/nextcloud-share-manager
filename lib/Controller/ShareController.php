<?php
declare(strict_types=1);

namespace OCA\ShareManager\Controller;

use OCA\ShareManager\Service\ShareTreeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use OCP\Share\IManager;
use OCP\Share\Exceptions\ShareNotFound;

class ShareController extends Controller {
    public function __construct(
        IRequest $request,
        private IManager $shareManager,
        private IUserSession $userSession,
        private ShareTreeService $treeService,
        private ILockingProvider $lockingProvider,
    ) {
        parent::__construct('sharemanager', $request);
    }

    #[NoAdminRequired]
    public function index(): JSONResponse {
        $uid = $this->userSession->getUser()?->getUID();
        if ($uid === null) return new JSONResponse(['error' => 'Anmeldung erforderlich.'], 401);
        try {
            return new JSONResponse($this->treeService->inventory($uid));
        } catch (\DomainException | \LengthException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 422);
        }
    }

    #[NoAdminRequired]
    public function preview(int $rootId, string $targetUser, bool $includeSharedAccess = false): JSONResponse {
        $uid = $this->userSession->getUser()?->getUID();
        if ($uid === null) return new JSONResponse(['error' => 'Anmeldung erforderlich.'], 401);
        try {
            return new JSONResponse($this->treeService->plan($uid, $rootId, $targetUser, $includeSharedAccess));
        } catch (\DomainException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 403);
        } catch (\InvalidArgumentException | \LengthException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 422);
        }
    }

    #[NoAdminRequired]
    public function revoke(int $rootId, string $targetUser, string $fingerprint, bool $includeSharedAccess = false): JSONResponse {
        $uid = $this->userSession->getUser()?->getUID();
        if ($uid === null) return new JSONResponse(['error' => 'Anmeldung erforderlich.'], 401);
        $lock = 'sharemanager:' . $uid . ':' . $rootId;
        try {
            // Serializes this app's operations, not modifications by other Nextcloud apps.
            $this->lockingProvider->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
        } catch (LockedException $e) {
            return new JSONResponse(['error' => 'Für diesen Baum läuft bereits eine Änderung.'], 409);
        }
        $deleted = [];
        $cascaded = [];
        try {
            $plan = $this->treeService->plan($uid, $rootId, $targetUser, $includeSharedAccess);
            if (!hash_equals($plan['fingerprint'], $fingerprint)) {
                return new JSONResponse(['error' => 'Freigaben oder Gruppenmitgliedschaften haben sich geändert. Vorschau erneut laden.'], 409);
            }
            if (!$plan['canExecute']) {
                return new JSONResponse(['error' => 'Vollständiger Entzug ist mit dieser Auswahl nicht möglich.', 'plan' => $plan], 422);
            }
            foreach ($plan['affected'] as $row) {
                try {
                    $share = $this->shareManager->getShareById($row['id']);
                } catch (ShareNotFound $e) {
                    $cascaded[] = $row['id'];
                    continue;
                }
                if ($share->getShareOwner() !== $uid) throw new \DomainException('Eigentümer hat sich geändert.');
                $this->treeService->ownedNode($uid, $share->getNodeId());
                $this->shareManager->deleteShare($share);
                $deleted[] = $row['id'];
            }
            $remaining = $this->treeService->remaining($uid, $rootId, $targetUser);
            if ($remaining !== []) {
                return new JSONResponse(['error' => 'Entzug nur teilweise abgeschlossen: weitere Zugriffswege bestehen oder wurden gleichzeitig angelegt.',
                    'deleted' => $deleted, 'cascaded' => $cascaded, 'remaining' => $remaining], 409);
            }
            return new JSONResponse(['revoked' => true, 'deleted' => $deleted, 'cascaded' => $cascaded,
                'remaining' => [], 'message' => 'Aktuell kein Freigabezugriff für diesen Benutzer im gewählten Baum. Neue Freigaben bleiben möglich.']);
        } catch (\DomainException $e) {
            return new JSONResponse(['error' => $e->getMessage(), 'deleted' => $deleted], 403);
        } catch (\InvalidArgumentException | \LengthException $e) {
            return new JSONResponse(['error' => $e->getMessage(), 'deleted' => $deleted], 422);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => 'Entzug konnte nicht vollständig geprüft werden. Liste und Vorschau erneut laden.',
                'deleted' => $deleted, 'verified' => false], 500);
        } finally {
            $this->lockingProvider->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
        }
    }

    #[NoAdminRequired]
    public function destroy(string $id): JSONResponse {
        $uid = $this->userSession->getUser()?->getUID();
        if ($uid === null) return new JSONResponse(['error' => 'Anmeldung erforderlich.'], 401);
        try {
            $share = $this->shareManager->getShareById($id);
        } catch (ShareNotFound $e) {
            return new JSONResponse(['error' => 'Freigabe nicht gefunden.'], 404);
        }
        if ($share->getSharedBy() !== $uid && $share->getShareOwner() !== $uid) {
            return new JSONResponse(['error' => 'Keine Berechtigung für diese Freigabe.'], 403);
        }
        if ($share->getShareOwner() === $uid) {
            try {
                $this->treeService->ownedNode($uid, $share->getNodeId());
            } catch (\DomainException $e) {
                return new JSONResponse(['error' => $e->getMessage()], 403);
            }
        }
        $this->shareManager->deleteShare($share);
        return new JSONResponse(['deleted' => true]);
    }
}

# Audit qualité — sylius-purchase-approval-plugin

> Généré le 2026-10-14 · Branche `chore/quality-tooling` · Outillage claude-craft (ECS, PHPStan, Rector, PHPUnit) exécuté via Docker `ghcr.io/sylius/sylius-php:8.4-alpine`. Revue qualitative par l'orchestrateur (lecture directe des fichiers).

## 1. Audit factuel (outils)

| Contrôle | Commande | Résultat |
|---|---|---|
| Style (ECS) | `vendor/bin/ecs check` | ✅ 0 erreur |
| Analyse statique (PHPStan **level max**) | `vendor/bin/phpstan analyse` | ✅ 0 erreur |
| Tests unitaires | `vendor/bin/phpunit --testsuite=unit` | ✅ 45 tests, 1 335 assertions |
| Validation composer | `composer validate --strict` | ✅ valide |
| Modernisation (Rector, dry-run) | `vendor/bin/rector process --dry-run` | ⚠️ 42 fichiers avec suggestions |
| Vulnérabilités dépendances | `composer audit` | ⚠️ 2 paquets abandonnés (pas de CVE) |
| Tests integration/functional | — | ⏭️ non exécutés localement (DB + app Sylius requise) — couverts par le CI |

**Rector** vient d'être ajouté (`rector/rector ^2.0`, `rector.php`, cibles `make rector` / `make rector-fix`). Les 42 fichiers signalés relèvent principalement de `FlipTypeControlToUseExclusiveTypeRector` — suggestions de modernisation non bloquantes, non appliquées. Lancer `make rector-fix` pour les appliquer puis relancer ECS/PHPStan/tests.

**Paquets abandonnés** : `behat/transliterator` et `php-http/message-factory` signalés par `composer audit`. Dépendances indirectes de Sylius ; aucune advisory CVE associée. À surveiller lors des mises à jour Sylius.

## 2. Audit qualitatif (revue par l'orchestrateur)

| Dimension | Score | Synthèse |
|---|---|---|
| PHP / Clean Archi / SOLID | **8/10** | `strict_types`, `final`, value objects, SRP bien découpé. Mutable setters sur l'entité sans invariant de classe. |
| Intégration Symfony / Sylius | **8/10** | Workflow configurable via `prepend()`, bridges optionnels élégants, `ResetInterface`, guard workflow shipment. Routage approve/reject API fragile. |
| Sécurité (OWASP 2025) | **9/10** | Anti-auto-approbation explicite, verrou pessimiste, double enforcement shop+API, CSRF partout, DQL paramétré, blocage paiement/expédition multicouche. |

### Points forts

- `declare(strict_types=1)` + `final` sur tous les services concrets (39 classes dans `src/`), enum-like `ApprovalPermissions` avec constructeur privé.
- Value objects immuables : `LimitAmount` (parse + MAX bound), `Decider` (factory methods), `ApprovalRequirement`.
- `ApprovalDecisionMaker::decide()` : verrou pessimiste (`LockMode::PESSIMISTIC_WRITE`) + re-lecture + `policy.check*` dans la même transaction — impossibilité de double approbation concurrente même sous charge.
- Anti-auto-approbation : `ApprovalPolicy::checkReject():l.69` compare `buyer->getId() === self->getId()` — un approbateur ne peut jamais décider sur sa propre commande.
- Blocage en profondeur : `ApprovalHold` (paiement via order-hold-plugin) + `ShipmentApprovalGuardListener` (expédition via workflow guard) + validators API + contrôleur shop → 4 couches indépendantes.
- Bridges optionnels (invoicing, webhook outbox, admin order creation) détectés à `prepend` time, sans couplage dur dans le core.
- `ExpireApprovalsCommand` : résilient (EM reset si fermé, log + skip sur exception individuelle, dry-run), pattern keyset (via `findPendingIdsRequestedBefore`) pour de gros volumes.
- `OrderApprovalRequestListener implements ResetInterface` : correct pour les workers long-running.
- `OrderApprovalRepository::markConfirmationSent()` : `Connection::update` direct pour éviter un second flush et une double confirmation.

## 3. Recommandations priorisées

### 🟠 Majeur

1. **Routage approve/reject par pattern de nom d'opération** (`AdminApprovalDecisionProcessor.php:38`, `ShopApprovalDecisionProcessor.php:44`, OWASP A04:2025). `str_ends_with($operation->getName(), '_approve')` : si un nom d'opération API Platform est modifié ou si un middleware renomme l'opération, une approbation peut silencieusement devenir un rejet sans erreur. → Ajouter un champ `type` explicite (`"approve"` / `"reject"`) dans `ApprovalDecisionInput` et router dessus, ou comparer l'URI de l'opération.

2. **Setters publics mutables sur `OrderApproval` sans invariant** (`Entity/OrderApproval.php:77-80`, `Entity/OrderApproval.php:97`, OWASP A04:2025). `setState()` et `setTotal()` sont publics et non validés : une sous-classe ou un code externe peut forcer `state = approved` ou falsifier le `total` sans passer par `ApprovalDecisionMaker`. La classe n'est pas `final` par convention Sylius, ce qui est correct, mais l'absence de protection dans l'entité crée un vecteur d'élévation de privilège. → Documenter explicitement que `setState()` est réservé au state machine ; envisager de restreindre à `protected` ou d'ajouter une assertion d'état valide.

3. **`func_get_args()` dans `AcknowledgementEmailSender`** (`Mailer/AcknowledgementEmailSender.php:32`). Bypass de signature d'interface pour passer des arguments extra-interface au décorateur sous-jacent. Si une décoratrice intermédiaire (tierce ou Sylius) intercepte l'appel, les arguments extra peuvent être perdus silencieusement, risquant un e-mail non envoyé ou une erreur non catchée. → Attendre l'extension officielle de `SenderInterface`, ajouter un log si `$extra` est non vide mais ignoré, ou envelopper dans un try/catch nommé.

### 🟡 Mineur

- **Controleur shop surchargé** (`Controller/Shop/ApprovalController.php:36-44`). 8 dépendances injectées : `ApprovalController` gère la liste, le détail, la décision et les limites. → Extraire `LimitsController` (limite updater, channel context) pour réduire la surface.
- **`OrderCancellationListener` sans flush explicite** (`EventListener/OrderCancellationListener.php:38-43`). La transition de workflow modifie l'état mais dépend du flush de l'appelant. Correct dans le contexte actuel (complète un `workflow.sylius_order.completed.cancel` synchrone), mais risque de surprise dans les tests ou les workers async. → Ajouter un commentaire documentant ce couplage.
- **DQL avec interpolation de constante** (`Repository/OrderApprovalRepository.php:95`). `sprintf("CASE WHEN o.state = '%s'…", STATE_PENDING)` : pas une injection (constante), mais moins lisible qu'un `HIDDEN` dédié ou qu'un paramètre lié. → Migrer vers un paramètre lié ou un alias `HIDDEN` pour uniformiser.
- **`OrderApprovalRepository` non `final`** (`Repository/OrderApprovalRepository.php:14`). Cohérent avec la convention Sylius (extensibilité repository), mais à documenter.
- Appliquer les 42 suggestions Rector (`make rector-fix`), puis relancer ECS, PHPStan et PHPUnit.

### ℹ️ Pour information (choix de conception assumés)

- Admins Sylius non bornés par `ApprovalPolicy` (limits, own-order) : design voulu, enregistré dans `Decider::administrator()`.
- `OrderApproval` non `final` : convention Sylius d'extensibilité ORM (XML mapped superclass).
- Pas de flush dans `OrderCancellationListener` : l'écouteur s'appuie sur la transaction de l'appelant (`sylius_order.cancel`).
- `behat/transliterator` et `php-http/message-factory` abandonnés : dépendances indirectes de Sylius core, non pilotables depuis ce plugin.

## 4. Verdict

Plugin **robuste et discipliné** : outillage au vert (ECS, PHPStan level max, 45 tests unitaires 1 335 assertions), typage strict, value objects, verrou pessimiste anti-concurrence, anti-auto-approbation, blocage paiement/expédition en 4 couches indépendantes. Le seul vrai risque opérationnel est le **routage approve/reject par pattern de nom** (item 1) — un changement de nom d'opération API Platform pourrait inverser silencieusement la décision ; à corriger avant la release publique. La mutabilité des setters d'entité (item 2) mérite une documentation ou une protection défensive. Le reste est du raffinement.

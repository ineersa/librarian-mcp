<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\LibrarianSettings;
use App\Index\Model\ModelEndpoint;
use App\Index\Model\ModelEndpointMode;
use App\Index\Model\ModelEndpoints;
use App\ObservationalMemory\Model\MemoryTokenLimits;
use App\Repository\LibrarianSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Edit-only singleton settings. The index creates the row with the env defaults when it is
 * missing and redirects to its edit page; there is no list, detail, new or delete.
 *
 * @extends AbstractCrudController<LibrarianSettings>
 */
#[AdminRoute(path: '/settings')]
#[IsGranted('ROLE_ADMIN')]
class LibrarianSettingsCrudController extends AbstractCrudController
{
    /** A blank field submits 0, which the Positive constraint rejects, instead of null into an int property. */
    private const string BLANK_AS_ZERO = '0';
    private const string TOKEN_HELP = 'Estimated tokens of unreflected plus retained observations in one session.';

    public function __construct(
        private readonly LibrarianSettingsRepository $settings,
        private readonly MemoryTokenLimits $tokenLimits,
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminUrlGenerator $urlGenerator,
        private readonly ModelEndpoints $endpoints,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return LibrarianSettings::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Settings')
            ->setEntityLabelInPlural('Settings')
            ->setPageTitle(Crud::PAGE_EDIT, 'Settings');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::DELETE, Action::DETAIL, Action::SAVE_AND_RETURN)
            ->update(Crud::PAGE_EDIT, Action::SAVE_AND_CONTINUE, static fn (Action $action) => $action->setLabel('Save settings'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield FormField::addFieldset('Claude Code sessions');
        yield IntegerField::new('claudeReflectAfterObservationTokens', 'Claude reflect after (tokens)')
            ->setFormTypeOption('empty_data', self::BLANK_AS_ZERO)
            ->setHelp('Run the Reflector once the working set exceeds this. '.self::TOKEN_HELP);
        yield IntegerField::new('claudeObservationsMaxTokens', 'Claude observations max (tokens)')
            ->setFormTypeOption('empty_data', self::BLANK_AS_ZERO)
            ->setHelp('Dropper target after a reflection. '.self::TOKEN_HELP);

        yield FormField::addFieldset('Hatfield sessions');
        yield IntegerField::new('hatfieldReflectAfterObservationTokens', 'Hatfield reflect after (tokens)')
            ->setFormTypeOption('empty_data', self::BLANK_AS_ZERO)
            ->setHelp('Run the Reflector once the working set exceeds this. '.self::TOKEN_HELP);
        yield IntegerField::new('hatfieldObservationsMaxTokens', 'Hatfield observations max (tokens)')
            ->setFormTypeOption('empty_data', self::BLANK_AS_ZERO)
            ->setHelp('Dropper target after a reflection. '.self::TOKEN_HELP);

        yield FormField::addFieldset('Backups');
        yield BooleanField::new('scheduledBackupsEnabled', 'Scheduled daily backups')
            ->setHelp('Off: the cron schedule skips the daily backup, for example on a laptop without GCS credentials. Operations › Cron runs › Run backup now still runs one.');

        yield from $this->endpointFields();
    }

    public function index(AdminContext $context): KeyValueStore|Response
    {
        $settings = $this->settings->current();
        if (null === $settings) {
            $settings = $this->tokenLimits->defaults();
            $this->entityManager->persist($settings);
            $this->entityManager->flush();
        }

        return $this->redirect($this->editUrl((int) $settings->id));
    }

    protected function getRedirectResponseAfterSave(AdminContext $context, string $action): RedirectResponse
    {
        $this->addFlash('success', 'Saved settings.');

        return $this->redirect($this->editUrl((int) $context->getEntity()->getPrimaryKeyValue()));
    }

    /** @return iterable<FormField|ChoiceField|TextField> */
    private function endpointFields(): iterable
    {
        $settings = $this->getContext()?->getEntity()->getInstance();
        $settings = $settings instanceof LibrarianSettings ? $settings : null;

        yield FormField::addFieldset('Embeddings')
            ->setHelp('Changing the server applies to the next search or indexing job. A different model than the existing indexes is rejected.');
        yield $this->modeField('embeddingMode', 'Embeddings server', $settings?->embeddingMode, $this->endpoints->resolveEmbedding(ModelEndpointMode::BuiltIn, null, null), $this->endpoints->embeddingFor($settings));
        yield TextField::new('embeddingApiUrl', 'Embeddings API URL')
            ->setRequired(false)
            ->setFormTypeOption('attr.placeholder', $this->endpoints->defaultEmbeddingUrl)
            ->setHelp('OpenAI-compatible base URL. Empty uses CODERANK_URL.');
        yield TextField::new('embeddingApiModel', 'Embeddings API model')
            ->setRequired(false)
            ->setFormTypeOption('attr.placeholder', $this->endpoints->defaultEmbeddingModel)
            ->setHelp(self::apiKeyHelp('CODERANK_MODEL', ModelEndpoints::ENV_EMBEDDING_API_KEY, $this->endpoints->hasEmbeddingApiKey()));

        yield FormField::addFieldset('Reranker');
        yield $this->modeField('rerankerMode', 'Reranker server', $settings?->rerankerMode, $this->endpoints->resolveReranker(ModelEndpointMode::BuiltIn, null, null), $this->endpoints->rerankerFor($settings));
        yield TextField::new('rerankerApiUrl', 'Reranker API URL')
            ->setRequired(false)
            ->setFormTypeOption('attr.placeholder', $this->endpoints->defaultRerankerUrl)
            ->setHelp('Voyage-compatible rerank base URL. Empty uses BGE_URL.');
        yield TextField::new('rerankerApiModel', 'Reranker API model')
            ->setRequired(false)
            ->setFormTypeOption('attr.placeholder', $this->endpoints->defaultRerankerModel)
            ->setHelp(self::apiKeyHelp('BGE_MODEL', ModelEndpoints::ENV_RERANKER_API_KEY, $this->endpoints->hasRerankerApiKey()));
    }

    /** Built-in is offered only while its service responds, or while it is already selected. */
    private function modeField(string $property, string $label, ?ModelEndpointMode $current, ModelEndpoint $builtIn, ModelEndpoint $active): ChoiceField
    {
        $builtInUp = $this->endpoints->responds($builtIn);
        $choices = [ModelEndpointMode::Api->label() => ModelEndpointMode::Api];
        if ($builtInUp || ModelEndpointMode::BuiltIn === $current) {
            $choices = [ModelEndpointMode::BuiltIn->label() => ModelEndpointMode::BuiltIn, ...$choices];
        }

        return ChoiceField::new($property, $label)
            ->setChoices($choices)
            ->renderExpanded()
            ->setHelp(\sprintf(
                'Active: %s (%s) — %s.%s',
                htmlspecialchars($active->url, \ENT_QUOTES),
                htmlspecialchars($active->model, \ENT_QUOTES),
                $this->endpoints->responds($active) ? 'responding' : 'not responding',
                $builtInUp ? '' : ' Built-in is offered once the models Compose profile is running.',
            ));
    }

    private static function apiKeyHelp(string $modelEnv, string $keyEnv, bool $keySet): string
    {
        return \sprintf('Empty uses %s. API key: %s env variable (%s); never sent to built-in services.', $modelEnv, $keyEnv, $keySet ? 'set' : 'not set');
    }

    private function editUrl(int $id): string
    {
        return $this->urlGenerator
            ->setController(self::class)
            ->setAction(Action::EDIT)
            ->setEntityId($id)
            ->generateUrl();
    }
}

<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Event;
use App\Entity\EventCategory;
use App\Repository\EventCategoryRepository;
use App\Repository\EventRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Left half of the kassa screen: pick a show series (event category), see all of
 * its upcoming shows and select one. The right half (the sale itself) follows later.
 *
 * Live components are reachable on every host under /_components, so the class
 * carries its own IsGranted: the register firewall alone does not protect it.
 */
#[AsLiveComponent(name: 'RegisterEventPicker', template: 'components/register/event_picker.html.twig')]
#[IsGranted('ROLE_REGISTER')]
final class RegisterEventPicker
{
    use DefaultActionTrait;

    private const MAX_SEARCH_LENGTH = 64;

    /** Id of the chosen category; empty means the first one. */
    #[LiveProp(writable: true, onUpdated: 'clearSelection')]
    public string $category = '';

    #[LiveProp(writable: true)]
    public string $search = '';

    /** Id of the selected show. */
    #[LiveProp]
    public ?string $selected = null;

    public function __construct(
        private EventCategoryRepository $categoryRepository,
        private EventRepository $eventRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return EventCategory[]
     */
    public function getCategories(): array
    {
        return $this->categoryRepository->findAllOrdered();
    }

    public function getCurrentCategory(): ?EventCategory
    {
        $categories = $this->getCategories();
        foreach ($categories as $category) {
            if ($category->id->toRfc4122() === $this->category) {
                return $category;
            }
        }

        return $categories[0] ?? null;
    }

    /**
     * @return Event[]
     */
    public function getEvents(): array
    {
        $category = $this->getCurrentCategory();
        if ($category === null) {
            return [];
        }

        $search = mb_substr(trim($this->search), 0, self::MAX_SEARCH_LENGTH);

        return $this->eventRepository->findForRegister(
            $category->id,
            $search === '' ? null : $search,
            \DateTimeImmutable::createFromInterface($this->clock->now()),
        );
    }

    /** The selected show, only when it is still part of the visible list. */
    public function getSelectedEvent(): ?Event
    {
        if ($this->selected === null) {
            return null;
        }
        foreach ($this->getEvents() as $event) {
            if ($event->id->toRfc4122() === $this->selected) {
                return $event;
            }
        }

        return null;
    }

    public function clearSelection(): void
    {
        $this->selected = null;
    }

    #[LiveAction]
    public function select(#[LiveArg] string $id): void
    {
        $this->selected = Uuid::isValid($id) ? $id : null;
    }

    /** Re-renders the list (the footer refresh button). */
    #[LiveAction]
    public function refresh(): void
    {
    }
}

<?php

namespace HiEvents\DomainObjects;

use Illuminate\Support\Collection;

class LiveChatSessionDomainObject extends Generated\LiveChatSessionDomainObjectAbstract
{
    private ?Collection $liveChatMessages = null;

    public function getLiveChatMessages(): ?Collection
    {
        return $this->liveChatMessages;
    }

    public function setLiveChatMessages(?Collection $liveChatMessages): self
    {
        $this->liveChatMessages = $liveChatMessages;

        return $this;
    }
}

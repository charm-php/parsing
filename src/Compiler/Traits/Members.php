<?php
namespace Charm\Parsing\Compiler\Traits;

trait Members {

    private $members = [];

    public function getMembers(int $filter = MemberBuilder::IS_PUBLIC | MemberBuilder::IS_PRIVATE | MemberBuilder::IS_STATIC | MemberBuilder::IS_FINAL | MemberBuilder::IS_ABSTRACT): array {
        $members = [];
        foreach ($this->members as $member) {
            if (0 !== ($member->getModifier() & $filter)) {
                $members[] = $member;
            }
        }
        return $members;
    }

    public function addMember(MemberBuilder $member): static {
        if (!in_array($member, $this->members)) {
            $this->members[] = $member;
        }
        return $this;
    }

    public function hasMember(MemberBuilder $member): bool {
        return in_array($member, $this->members);
    }

    public function removeMember(MemberBuilder $member): static {
        foreach ($this->members as $key => $existingMember) {
            if ($existingMember === $member) {
                unset($this->members[$key]);
                $this->members = array_values($this->members);
                return $this;
            }
        }
        return $this;
    }

    public function removeMemberByName(string $name): static {
        $member = $this->getMemberByName($name);
        if ($member) {
            return $this->removeMember($member);
        }
        return $this;
    }

    public function getMemberByName(string $name): ?MemberBuilder {
        foreach ($this->members as $member) {
            if ($member->getName() === $name) {
                return $member;
            }
        }
        return null;
    }

    public function withMember(MemberBuilder $member): static {
        return (clone $this)->addMember($member);
    }

    public function withoutMember(MemberBuilder $member): static {
        return (clone $this)->removeMember($member);
    }

    public function withoutMemberByName(string $name): static {
        return (clone $this)->removeMemberByName($name);
    }

    protected function cloneMembers() {
        foreach ($this->members as $key => $cloneMember) {
            $this->members[$key] = clone $cloneMember;
        }
    }

}

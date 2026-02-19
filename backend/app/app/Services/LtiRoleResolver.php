<?php

namespace App\Services;

class LtiRoleResolver
{
    private const INSTRUCTOR_ROLE_FRAGMENTS = [
        'administrator',
        'contentdeveloper',
        'coursebuilder',
        'designer',
        'instructor',
        'teacher',
        'teachingassistant',
        'tutor',
    ];

    /**
     * @param mixed $roles
     */
    public function isInstructor($roles): bool
    {
        if (!is_array($roles)) {
            return false;
        }

        foreach ($roles as $role) {
            if (!is_string($role) || trim($role) === '') {
                continue;
            }

            if ($this->isInstructorRole($role)) {
                return true;
            }
        }

        return false;
    }

    private function isInstructorRole(string $role): bool
    {
        $normalized = strtolower($role);
        $collapsed = (string) preg_replace('/[^a-z]/', '', $normalized);
        if ($collapsed === '') {
            return false;
        }

        foreach (self::INSTRUCTOR_ROLE_FRAGMENTS as $fragment) {
            if (str_contains($collapsed, $fragment)) {
                return true;
            }
        }

        return false;
    }
}

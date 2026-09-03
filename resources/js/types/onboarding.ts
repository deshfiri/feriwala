export type OnboardingStepState = 'done' | 'current' | 'blocked' | 'upcoming';

export type OnboardingStep = {
    key: string;
    label: string;
    position: number;
    state: OnboardingStepState;
};

export type OnboardingAction = {
    label: string;
    route: string;
};

export type OnboardingProgress = {
    is_complete: boolean;
    current_step: string;
    steps: OnboardingStep[];
    action: OnboardingAction | null;
    blocked_reason: string | null;
    /** The reviewer's message to the applicant. Never their internal note. */
    feedback: string | null;
    status: {
        value: string;
        label: string;
        tone: 'success' | 'warning' | 'danger' | 'info' | 'neutral';
    };
};

export type LocaleCode = 'en' | 'bn';

export type LocaleOption = {
    value: LocaleCode;
    label: string;
};

export type LocaleState = {
    current: LocaleCode;
    direction: 'ltr' | 'rtl';
    available: LocaleOption[];
};

/**
 * Translation lines for the active locale, shared from the server as a nested
 * object keyed by file then by line — `common.actions.save`.
 */
export type TranslationTree = {
    [key: string]: string | TranslationTree;
};

import {useQuery} from '@tanstack/react-query';
import {DefaultEmailTemplatesContext, emailTemplateClient} from '../api/email-template.client';

export const GET_DEFAULT_EMAIL_TEMPLATES_QUERY_KEY = 'getDefaultEmailTemplates';

export const useGetDefaultEmailTemplates = (enabled: boolean = false, context?: DefaultEmailTemplatesContext) => {
    return useQuery({
        queryKey: [GET_DEFAULT_EMAIL_TEMPLATES_QUERY_KEY, context ?? null],
        queryFn: () => emailTemplateClient.getDefaultTemplates(context),
        enabled,
    });
};

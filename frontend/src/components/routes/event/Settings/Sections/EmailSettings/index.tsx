import {GeneralEmailSettings} from './GeneralEmailSettings';
import {TemplateSettings} from './TemplateSettings';
import {EmailLogoSettings} from './EmailLogoSettings';

export const EmailSettings = () => {
    return (
        <div>
            <GeneralEmailSettings />
            <EmailLogoSettings />
            <TemplateSettings />
        </div>
    );
};

export default EmailSettings;

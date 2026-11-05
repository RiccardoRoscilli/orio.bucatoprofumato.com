import './bootstrap';
import Alpine from 'alpinejs';
import cassa from './cassa';
import customerModal from './customer-modal';

window.Alpine = Alpine;
Alpine.data('cassa', cassa);
Alpine.data('customerModal', customerModal);
Alpine.start();

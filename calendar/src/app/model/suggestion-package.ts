import {Suggestion} from './suggestion';

export interface SuggestionPackage {
  id?: number;
  churchId?: number;
  senderName?: string;
  senderEmail?: string;
  senderUserId?: number;
  senderMessage?: string;
  suggestions: Suggestion[];
  state: SuggestionState;
  createdAt: Date;
  /** Ki és mikor döntött a javaslatról. Korábban ez sehol nem tárolódott. */
  handledByUserId?: number;
  handledByName?: string;
  handledAt?: string;
}

export enum SuggestionState {
  ACCEPTED = 'ACCEPTED',
  REJECTED = 'REJECTED',
  PENDING = 'PENDING'
}
